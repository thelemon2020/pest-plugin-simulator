<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Codegen;

use NativePhp\Simulator\Exceptions\SimulatorException;
use PDO;
use PDOException;

/**
 * What a step wrote to the app's database: the rows it added and the rows it changed, found
 * by comparing a copy of every table from before the step with one from after.
 *
 * @phpstan-type Row array<string, int|float|string|null>
 * @phpstan-type Snapshot array<string, array<int, Row>>
 * @phpstan-type Change array{table: string, rowid: int, kind: 'added'|'changed', row: Row, changed: list<string>, suggested: list<string>, count: int}
 */
final class Changes
{
    /**
     * Tables a framework writes on its own, which say nothing about what the step did.
     */
    private const IGNORED = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    /** A table with more rows than this is too big to copy on every step, and is skipped. */
    private const MOST_ROWS = 5000;

    /**
     * Every table's rows, by rowid.
     *
     * @return Snapshot
     */
    public static function snapshot(PDO $database): array
    {
        $snapshot = [];

        foreach (array_column(self::rows($database, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'name') as $table) {
            if (! is_string($table) || in_array($table, self::IGNORED, true)) {
                continue;
            }

            $quoted = '"'.str_replace('"', '""', $table).'"';

            try {
                $count = (int) (self::rows($database, "SELECT COUNT(*) AS n FROM {$quoted}")[0]['n'] ?? 0);

                if ($count > self::MOST_ROWS) {
                    continue;
                }

                // A WITHOUT ROWID table has no rowid to tell its rows apart by, and fails here.
                $rows = self::rows($database, "SELECT rowid AS __rowid, * FROM {$quoted}");
            } catch (PDOException) {
                continue;
            }

            foreach ($rows as $row) {
                $rowid = (int) $row['__rowid'];
                unset($row['__rowid']);
                $snapshot[$table][$rowid] = $row;
            }

            $snapshot[$table] ??= [];
        }

        return $snapshot;
    }

    /**
     * @return list<Row>
     */
    private static function rows(PDO $database, string $sql): array
    {
        $statement = $database->query($sql);
        $rows = [];

        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = [];

            foreach ((array) $fetched as $column => $value) {
                // SQLite hands back integers, reals, text, blobs as strings, and null.
                $row[(string) $column] = is_int($value) || is_float($value) || is_string($value) ? $value : null;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The rows $after has that $before did not, and the rows whose columns changed.
     *
     * @param  Snapshot  $before
     * @param  Snapshot  $after
     * @return list<Change>
     */
    public static function between(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $table => $rows) {
            foreach ($rows as $rowid => $row) {
                $old = $before[$table][$rowid] ?? null;

                $kind = $old === null ? 'added' : 'changed';
                $changed = $old === null
                    ? array_keys($row)
                    : array_keys(array_filter($row, fn ($value, string $column): bool => ($old[$column] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

                if ($changed !== []) {
                    $changes[] = ['table' => $table, 'rowid' => $rowid, 'kind' => $kind, 'row' => $row, 'changed' => $changed, 'suggested' => self::suggested($row, $changed), 'count' => count($rows)];
                }
            }
        }

        return $changes;
    }

    /**
     * The assertDatabaseHas() for a change, on $columns with the values the row has now. By
     * default, the columns it suggests: those it wrote, without the ones a test cannot know
     * ahead of time. An id and a timestamp come out different on every run.
     *
     * @param  Change  $change
     * @param  list<string>|null  $columns
     */
    public static function has(array $change, ?array $columns = null): ?Step
    {
        $data = [];

        foreach ($columns ?? $change['suggested'] as $column) {
            if (! array_key_exists($column, $change['row'])) {
                throw new SimulatorException("[{$change['table']}] has no [{$column}] column.");
            }

            $data[$column] = $change['row'][$column];
        }

        return $data === [] ? null : new Step('assertDatabaseHas', [$change['table'], $data]);
    }

    /**
     * Of the columns a step wrote, those a test can know ahead of time.
     *
     * @param  Row  $row
     * @param  list<string>  $changed
     * @return list<string>
     */
    private static function suggested(array $row, array $changed): array
    {
        return array_values(array_filter(
            $changed,
            fn (string $column): bool => $row[$column] !== null && ! self::random($column, $row[$column]),
        ));
    }

    /**
     * Whether a column comes out different on every run: an id, a timestamp, a token, a
     * password hash, or a UUID or ULID.
     */
    private static function random(string $column, int|float|string $value): bool
    {
        if ($column === 'id' || str_ends_with($column, '_at') || in_array($column, ['uuid', 'ulid', 'token', 'password'], true)
            || str_ends_with($column, '_token') || str_ends_with($column, '_uuid') || str_ends_with($column, '_ulid')) {
            return true;
        }

        return is_string($value) && preg_match('/^(\$2[aby]\$|\$argon2)|^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$|^[0-9A-HJKMNP-TV-Z]{26}$/i', $value) === 1;
    }

    /**
     * The assertDatabaseCount() for the table a row was added to.
     *
     * @param  Change  $change
     */
    public static function count(array $change): Step
    {
        return new Step('assertDatabaseCount', [$change['table'], $change['count']]);
    }
}
