<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Codegen;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use NativePhp\Simulator\Driver;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\SqliteSnapshot;
use Throwable;

/**
 * The rows a recorded test starts from, one list per model: put into a copy of the app's
 * database for the device, and written as the lines that make the same rows at the top of
 * the test. Its first screen() copies the test's database into the app, rows and all.
 *
 * Both empty each model's table first and then create its rows, in the order the models
 * were added, so the device gets what the test will get, ids included: the copy starts
 * from the same database the test does.
 *
 * @phpstan-import-type ModelInfo from Models
 * @phpstan-import-type Values from Models
 *
 * @phpstan-type Entry array{model: ModelInfo, rows: list<Values>, factory?: bool}
 */
final class Setup
{
    private const CONNECTION = 'pest_simulator_codegen';

    /**
     * @param  list<Entry>  $entries
     */
    public function __construct(public readonly array $entries) {}

    /**
     * Copies the app's database, makes the rows in the copy, and installs it on the device.
     * The app is stopped for that, as it is for a test's first screen().
     */
    public function apply(Driver $driver): void
    {
        $path = tempnam(sys_get_temp_dir(), 'codegen-setup-');

        if ($path === false) {
            throw new SimulatorException('Could not make a copy of the database to set up.');
        }

        try {
            $source = DB::connection();

            if ($source->getDriverName() !== 'sqlite') {
                throw new SimulatorException('Setup needs a SQLite database. Set DB_CONNECTION=sqlite.');
            }

            SqliteSnapshot::write($source->getPdo(), $path);
            config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]]);
            DB::purge(self::CONNECTION);

            try {
                $this->make();
            } finally {
                DB::disconnect(self::CONNECTION);
            }

            $driver->installDatabase($path);
        } finally {
            foreach ([$path, $path.'-wal', $path.'-shm', $path.'-journal'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * The lines that go before the test's screen(), with the use lines they need. Empty
     * when nothing is set up.
     *
     * @return array{uses: list<string>, code: string}
     */
    public function code(): array
    {
        if ($this->entries === []) {
            return ['uses' => [], 'code' => ''];
        }

        $uses = [];
        $lines = [];

        foreach (array_reverse($this->entries) as $entry) {
            $lines[] = $entry['model']['name'].($entry['model']['softDeletes'] ? '::withTrashed()->forceDelete();' : '::query()->delete();');
        }

        foreach ($this->entries as $entry) {
            $uses[] = 'use '.$entry['model']['class'].';';

            if ($entry['factory'] ?? false) {
                array_push($lines, ...self::factoryLines($entry['model']['name'], $entry['rows']));

                continue;
            }

            $create = $entry['model']['fillable'] ? 'create' : 'forceCreate';

            foreach ($entry['rows'] as $row) {
                $lines[] = $entry['model']['name'].'::'.$create.'('.Step::literal($row).');';
            }
        }

        return ['uses' => array_values(array_unique($uses)), 'code' => implode("\n", $lines)];
    }

    /**
     * One factory call per row with the values it pins, and rows that pin nothing next to
     * each other as one count().
     *
     * @param  list<Values>  $rows
     * @return list<string>
     */
    private static function factoryLines(string $model, array $rows): array
    {
        $lines = [];
        $index = 0;

        while ($index < count($rows)) {
            if ($rows[$index] !== []) {
                $lines[] = $model.'::factory()->create('.Step::literal($rows[$index]).');';
                $index++;

                continue;
            }

            $bare = 0;

            while ($index < count($rows) && $rows[$index] === []) {
                $bare++;
                $index++;
            }

            $lines[] = $model.'::factory()'.($bare > 1 ? "->count({$bare})" : '').'->create();';
        }

        return $lines;
    }

    private function make(): void
    {
        DB::connection(self::CONNECTION)->transaction(function (): void {
            foreach (array_reverse($this->entries) as $entry) {
                $query = $this->query($entry['model']['class']);
                $entry['model']['softDeletes'] ? $query->withoutGlobalScopes()->forceDelete() : $query->delete();
            }

            foreach ($this->entries as $entry) {
                foreach ($entry['rows'] as $row) {
                    try {
                        match (true) {
                            $entry['factory'] ?? false => $this->factory($entry['model']['class'])->create($row),
                            $entry['model']['fillable'] => $this->query($entry['model']['class'])->create($row),
                            default => $this->query($entry['model']['class'])->forceCreate($row),
                        };
                    } catch (Throwable $exception) {
                        throw new SimulatorException("Could not create a {$entry['model']['name']}: ".strtok($exception->getMessage(), "\n"), previous: $exception);
                    }
                }
            }
        });
    }

    /**
     * The model's factory, making rows in the copy.
     *
     * @param  class-string<Model>  $class
     * @return Factory<Model>
     */
    private function factory(string $class): Factory
    {
        $factory = method_exists($class, 'factory') ? $class::factory() : null;

        if (! $factory instanceof Factory) {
            throw new SimulatorException(class_basename($class).' has no factory to use.');
        }

        return $factory->connection(self::CONNECTION);
    }

    /**
     * @param  class-string<Model>  $class
     * @return Builder<Model>
     */
    private function query(string $class): Builder
    {
        return $class::on(self::CONNECTION);
    }
}
