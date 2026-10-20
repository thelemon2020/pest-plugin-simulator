<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use BackedEnum;
use DateTimeInterface;
use NativePhp\Simulator\Exceptions\SimulatorException;
use PDO;
use PDOException;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * The app's database as the device has it now, the one TestDatabase copied in and the app
 * has written to since. It is pulled into a file of this process's own, checked, and opened
 * with writes turned off.
 *
 * A pull is not a snapshot: SQLite can be part way through writing a page while the file is
 * read. `PRAGMA quick_check` catches a copy torn that way, and it is pulled again. The
 * write-ahead log is pulled with it, in case the app's database uses one.
 *
 * One pull serves until the next Screen step, which can change what the app has written.
 */
final class DeviceDatabase
{
    public const PATH = 'database/database.sqlite';

    private const ATTEMPTS = 3;

    /** How many rows a failure message shows, as Laravel's own assertion does. */
    private const SHOWN = 3;

    private static ?PDO $open = null;

    private static ?string $current = null;

    /** @var list<string> */
    private static array $files = [];

    /**
     * The device's database, pulled now unless this step already pulled it.
     */
    public static function open(Driver $driver): PDO
    {
        if (self::$open !== null) {
            return self::$open;
        }

        $failure = null;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $path = self::pull($driver);

            try {
                self::check($path);
                self::$current = $path;

                return self::$open = self::readOnly($path);
            } catch (PDOException|SimulatorException $exception) {
                $failure = $exception;
            }
        }

        throw new SimulatorException(
            "Could not read the app's database: it was still being written after ".self::ATTEMPTS.' pulls. '.$failure->getMessage(),
            previous: $failure,
        );
    }

    /**
     * The next open() pulls the database again.
     */
    public static function forget(): void
    {
        self::$open = null;
    }

    /**
     * Forgets the pull and deletes every copy this process made.
     */
    public static function reset(): void
    {
        self::$open = null;
        self::$current = null;

        foreach (self::$files as $file) {
            foreach ([$file, $file.'-wal', $file.'-shm', $file.'-journal'] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        self::$files = [];
    }

    /**
     * Copies the last pull into a failure's directory, as `device.sqlite`.
     *
     * @return list<string> the file written, if there was a pull to copy
     */
    public static function save(string $root): array
    {
        if (self::$current === null || ! is_file(self::$current)) {
            return [];
        }

        return @copy(self::$current, $root.'/device.sqlite') ? [$root.'/device.sqlite'] : [];
    }

    /**
     * A table's name, from its name or from an Eloquent model, its class or an instance,
     * as Laravel's assertDatabaseHas() takes it.
     */
    public static function table(string|object $table): string
    {
        if (is_string($table) && class_exists($table) && method_exists($table, 'getTable')) {
            $table = new $table;
        }

        if (is_object($table) && method_exists($table, 'getTable')) {
            $name = $table->getTable();

            return is_string($name) ? $name : throw new SimulatorException('The model did not name its table.');
        }

        if (! is_string($table)) {
            throw new SimulatorException('Name the table, or pass an Eloquent model or its class.');
        }

        return $table;
    }

    public static function hasTable(PDO $database, string $table): bool
    {
        $statement = $database->prepare("SELECT 1 FROM sqlite_master WHERE type IN ('table', 'view') AND name = ?");
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * How many rows hold every value in $data. A null value matches a null column.
     *
     * @param  array<string, mixed>  $data
     */
    public static function count(PDO $database, string $table, array $data = []): int
    {
        self::requireColumns($database, $table, array_keys($data));
        [$where, $bindings] = self::where($data);
        $statement = $database->prepare('SELECT COUNT(*) FROM '.self::quote($table).$where);
        $statement->execute($bindings);

        return (int) $statement->fetchColumn();
    }

    /**
     * What a failure shows of a table, as Laravel's does: rows that share the first value
     * asked for, or else the first rows there are, with only the columns asked for.
     *
     * @param  array<string, mixed>  $data
     */
    public static function similar(PDO $database, string $table, array $data): string
    {
        if ($data !== []) {
            $first = array_key_first($data);
            $similar = self::rows($database, $table, array_keys($data), [$first => $data[$first]]);

            if ($similar[0] !== []) {
                return 'Found similar results: '.self::json($similar[0]).self::others($similar[1]);
            }
        }

        [$rows, $total] = self::rows($database, $table, array_keys($data));

        return $rows === [] ? 'The table is empty.' : 'Found: '.self::json($rows).self::others($total);
    }

    /**
     * The rows that match, for a failure that found some it should not have.
     *
     * @param  array<string, mixed>  $data
     */
    public static function found(PDO $database, string $table, array $data): string
    {
        [$rows, $total] = self::rows($database, $table, [], $data);

        return 'Found: '.self::json($rows).self::others($total);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function json(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function pull(Driver $driver): string
    {
        $directory = sys_get_temp_dir().'/pest-simulator-'.getmypid();

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new SimulatorException("Could not create [{$directory}] to pull the app's database into.");
        }

        // A file of its own each time: a PDO that deviceDatabase() handed out earlier
        // still reads the one it opened.
        $path = $directory.'/device-'.bin2hex(random_bytes(4)).'.sqlite';
        self::$files[] = $path;

        if (! $driver->pullAppFile(self::PATH, $path)) {
            throw new SimulatorException('The app has no database yet. There is no '.self::PATH.' in its storage.');
        }

        $driver->pullAppFile(self::PATH.'-wal', $path.'-wal');

        return $path;
    }

    /**
     * Fails on a torn copy. A copy that passes has its write-ahead log folded in and the
     * log turned off, so a read-only connection needs no -shm file beside it.
     */
    private static function check(string $path): void
    {
        $database = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        try {
            $result = $database->query('PRAGMA quick_check');
            $answer = $result === false ? false : $result->fetchColumn();
            $result = null;

            if ($answer !== 'ok') {
                throw new SimulatorException('PRAGMA quick_check said: '.(is_string($answer) ? $answer : 'nothing'));
            }

            $database->exec('PRAGMA journal_mode = DELETE');
        } finally {
            $database = null;
        }
    }

    private static function readOnly(string $path): PDO
    {
        $database = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $database->exec('PRAGMA query_only = ON');

        return $database;
    }

    /**
     * SQLite reads a quoted name that is not a column as a string, so a typo in a column
     * would only ever match nothing. It fails instead.
     *
     * @param  list<int|string>  $columns
     */
    private static function requireColumns(PDO $database, string $table, array $columns): void
    {
        if ($columns === []) {
            return;
        }

        $result = $database->query('PRAGMA table_info('.self::quote($table).')');
        $known = $result === false ? [] : array_column($result->fetchAll(PDO::FETCH_ASSOC), 'name');
        $missing = array_values(array_diff(array_map(strval(...), $columns), $known));

        if ($known !== [] && $missing !== []) {
            throw new SimulatorException("The device's [{$table}] table has no [".implode('], [', $missing).'] '.(count($missing) === 1 ? 'column' : 'columns').'.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: list<mixed>}
     */
    private static function where(array $data): array
    {
        $clauses = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            $value = self::value((string) $column, $value);

            if ($value === null) {
                $clauses[] = self::quote((string) $column).' IS NULL';

                continue;
            }

            $clauses[] = self::quote((string) $column).' = ?';
            $bindings[] = $value;
        }

        return [$clauses === [] ? '' : ' WHERE '.implode(' AND ', $clauses), $bindings];
    }

    /**
     * A value as SQLite stores it, the way Laravel's query builder would bind it.
     */
    private static function value(string $column, mixed $value): int|float|string|null
    {
        return match (true) {
            $value === null, is_int($value), is_float($value), is_string($value) => $value,
            is_bool($value) => (int) $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof Stringable => (string) $value,
            default => throw new SimulatorException("Cannot compare [{$column}] with a ".get_debug_type($value).'. Give it a string, a number, a bool, or null.'),
        };
    }

    /**
     * @param  list<string>  $columns  the columns to show, or every one
     * @param  array<string, mixed>  $data
     * @return array{0: list<array<string, mixed>>, 1: int} the first rows, and how many match
     */
    private static function rows(PDO $database, string $table, array $columns, array $data = []): array
    {
        [$where, $bindings] = self::where($data);
        $select = $columns === [] ? '*' : implode(', ', array_map(self::quote(...), $columns));

        try {
            $statement = $database->prepare("SELECT {$select} FROM ".self::quote($table).$where.' LIMIT '.self::SHOWN);
            $statement->execute($bindings);
            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            // A column asked for that the table does not have. Show the rows as they are.
            $statement = $database->prepare('SELECT * FROM '.self::quote($table).' LIMIT '.self::SHOWN);
            $statement->execute();
            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $data = [];
        }

        return [$rows, self::count($database, $table, $data)];
    }

    private static function others(int $total): string
    {
        $more = $total - self::SHOWN;

        return $more > 0 ? " and {$more} others." : '';
    }

    private static function quote(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
