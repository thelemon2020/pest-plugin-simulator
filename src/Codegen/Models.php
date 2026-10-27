<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Codegen;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use NativePhp\Simulator\Exceptions\SimulatorException;
use ReflectionClass;
use Stringable;
use Throwable;

/**
 * The app's Eloquent models, found in its models directory, with the columns a recording
 * can set on each, and rows to start from: made by the model's factory, or from the
 * columns' defaults.
 *
 * @phpstan-type Column array{name: string, type: string, nullable: bool, default: string|null}
 * @phpstan-type ModelInfo array{class: class-string<Model>, name: string, table: string, columns: list<Column>, factory: bool, fillable: bool, softDeletes: bool}
 * @phpstan-type Values array<string, int|float|string|null>
 */
final class Models
{
    /** The type of a column the page added, which the schema did not describe. */
    public const UNKNOWN = 'unknown';

    /** Columns the database fills in itself. */
    private const AUTOMATIC = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * @return list<ModelInfo>
     */
    public static function in(string $directory, string $namespace): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $models = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(rtrim($directory, '/')) + 1, -4);
            $class = rtrim($namespace, '\\').'\\'.str_replace('/', '\\', $relative);

            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            try {
                $models[] = self::describe($class);
            } catch (Throwable) {
                // A model whose table does not exist yet has nothing to set up.
                continue;
            }
        }

        usort($models, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $models;
    }

    /**
     * @param  class-string<Model>  $class
     * @return ModelInfo
     */
    public static function describe(string $class): array
    {
        $model = new $class;
        $table = $model->getTable();
        $schema = Schema::connection($model->getConnectionName());

        if (! $schema->hasTable($table)) {
            throw new SimulatorException("[{$table}] has no table yet. Run the migrations.");
        }

        $columns = [];

        foreach ($schema->getColumns($table) as $column) {
            $name = (string) $column['name'];

            if (in_array($name, self::AUTOMATIC, true)) {
                continue;
            }

            // A model that names its fillable columns sets only those. One that guards
            // nothing, or everything, gets every column, through forceCreate().
            if ($model->getFillable() !== [] && ! $model->isFillable($name)) {
                continue;
            }

            $columns[] = [
                'name' => $name,
                'type' => strtolower($column['type_name']),
                'nullable' => $column['nullable'],
                'default' => self::defaultValue($column['default'] ?? null),
            ];
        }

        $traits = class_uses_recursive($class);

        return [
            'class' => $class,
            'name' => class_basename($class),
            'table' => $table,
            'columns' => $columns,
            'factory' => isset($traits[HasFactory::class]),
            'fillable' => $model->getFillable() !== [],
            'softDeletes' => isset($traits[SoftDeletes::class]),
        ];
    }

    /**
     * The model with columns the page added by name, for one the schema or the fillable
     * list did not show. An added column's type is not known, so its values are typed by
     * how they look. One the model would not fill makes it use forceCreate().
     *
     * @param  ModelInfo  $model
     * @param  list<string>  $names
     * @return ModelInfo
     */
    public static function withFields(array $model, array $names): array
    {
        $known = array_column($model['columns'], 'name');
        $instance = new $model['class'];

        foreach (array_values(array_unique($names)) as $name) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new SimulatorException("[{$name}] is not a column name.");
            }

            if (in_array($name, $known, true)) {
                continue;
            }

            $model['columns'][] = ['name' => $name, 'type' => self::UNKNOWN, 'nullable' => true, 'default' => null];

            if (! $instance->isFillable($name)) {
                $model['fillable'] = false;
            }
        }

        return $model;
    }

    /**
     * $count rows to start from, as the page shows them: the model's factory makes them, or
     * each column takes its default.
     *
     * @param  ModelInfo  $model
     * @return list<array<string, string|null>>
     */
    public static function rows(array $model, int $count): array
    {
        $rows = [];

        for ($row = 0; $row < $count; $row++) {
            $made = $model['factory'] ? self::made($model['class']) : [];
            $values = [];

            foreach ($model['columns'] as $column) {
                $values[$column['name']] = array_key_exists($column['name'], $made)
                    ? $made[$column['name']]
                    : ($column['default'] ?? ($column['nullable'] ? null : self::blank($column['type'])));
            }

            $rows[] = $values;
        }

        return $rows;
    }

    /**
     * What the page sent for a row, typed by its columns: a number for a number column, and
     * null for an empty value in a column that allows it. A column it left out stays out.
     * With $factory, an empty value is left out too, for the model's factory to fill.
     *
     * @param  ModelInfo  $model
     * @param  array<array-key, mixed>  $row
     * @return Values
     */
    public static function values(array $model, array $row, bool $factory = false): array
    {
        $values = [];

        foreach ($model['columns'] as $column) {
            // A column the page did not send is left to the database's default.
            if (! array_key_exists($column['name'], $row)) {
                continue;
            }

            $value = $row[$column['name']];
            $value = is_scalar($value) ? (string) $value : null;
            $unknown = $column['type'] === self::UNKNOWN;

            // An added column left empty was not filled in, and whether it allows null is
            // not known, so it is left out too.
            if (($unknown || $factory) && ($value === null || $value === '')) {
                continue;
            }

            if ($value === null || ($value === '' && $column['nullable'])) {
                $values[$column['name']] = null;

                continue;
            }

            $values[$column['name']] = match (true) {
                ($unknown || self::integer($column['type'])) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
                ($unknown || self::real($column['type'])) && is_numeric($value) => (float) $value,
                default => $value,
            };
        }

        return $values;
    }

    /**
     * One row from the model's factory, as the strings the page shows.
     *
     * @param  class-string<Model>  $class
     * @return array<string, string|null>
     */
    private static function made(string $class): array
    {
        $made = [];

        if (! method_exists($class, 'factory')) {
            return [];
        }

        $model = $class::factory()->make();

        if (! $model instanceof Model) {
            return [];
        }

        foreach ($model->getAttributes() as $name => $value) {
            $made[(string) $name] = match (true) {
                $value === null => null,
                is_bool($value) => $value ? '1' : '0',
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
                $value instanceof BackedEnum => (string) $value->value,
                is_scalar($value) || $value instanceof Stringable => (string) $value,
                default => (string) json_encode($value),
            };
        }

        return $made;
    }

    /**
     * A default as SQLite writes it in the schema: 'unpaid' in quotes, or a bare number.
     */
    private static function defaultValue(mixed $default): ?string
    {
        if (! is_scalar($default)) {
            return null;
        }

        $default = trim((string) $default);

        if (preg_match("/^'(.*)'$/s", $default, $quoted) === 1) {
            return str_replace("''", "'", $quoted[1]);
        }

        // An expression such as CURRENT_TIMESTAMP is the database's to fill in.
        return is_numeric($default) ? $default : null;
    }

    private static function blank(string $type): string
    {
        return self::integer($type) || self::real($type) ? '0' : '';
    }

    private static function integer(string $type): bool
    {
        return str_contains($type, 'int') || $type === 'boolean';
    }

    private static function real(string $type): bool
    {
        return in_array($type, ['real', 'float', 'double', 'decimal', 'numeric'], true);
    }
}
