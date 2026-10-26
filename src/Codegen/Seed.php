<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Codegen;

use PDO;

/**
 * A beforeEach() that puts tables back the way the app had them when the recording
 * started, so a recorded test starts from the rows it was recorded against: the same ids,
 * the same counts.
 *
 * @phpstan-import-type Snapshot from Changes
 */
final class Seed
{
    /**
     * Which tables each table's foreign keys point to.
     *
     * @return array<string, list<string>>
     */
    public static function references(PDO $database): array
    {
        $references = [];
        $tables = $database->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");

        foreach ($tables === false ? [] : $tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (! is_string($table)) {
                continue;
            }

            $keys = $database->query('PRAGMA foreign_key_list("'.str_replace('"', '""', $table).'")');

            foreach ($keys === false ? [] : $keys->fetchAll(PDO::FETCH_ASSOC) as $key) {
                if (is_array($key) && is_string($key['table'] ?? null) && $key['table'] !== $table) {
                    $references[$table][] = $key['table'];
                }
            }
        }

        return $references;
    }

    /**
     * The beforeEach() for $tables, from $start. A table another one points to is filled
     * first and emptied last, so the app's foreign keys hold at every line.
     *
     * @param  Snapshot  $start
     * @param  list<string>  $tables
     * @param  array<string, list<string>>  $references
     */
    public static function code(array $start, array $tables, array $references = []): string
    {
        $ordered = self::order(array_values(array_filter($tables, fn (string $table): bool => isset($start[$table]))), $references);

        if ($ordered === []) {
            return '';
        }

        $lines = ['use Illuminate\Support\Facades\DB;', '', 'beforeEach(function () {'];

        foreach (array_reverse($ordered) as $table) {
            $lines[] = '    DB::table('.var_export($table, true).')->delete();';
        }

        foreach ($ordered as $table) {
            if ($start[$table] === []) {
                continue;
            }

            $lines[] = '    DB::table('.var_export($table, true).')->insert([';

            foreach ($start[$table] as $row) {
                $lines[] = '        '.Step::literal($row).',';
            }

            $lines[] = '    ]);';
        }

        $lines[] = '});';

        return implode("\n", $lines)."\n";
    }

    /**
     * $tables with each one after the tables it points to.
     *
     * @param  list<string>  $tables
     * @param  array<string, list<string>>  $references
     * @return list<string>
     */
    private static function order(array $tables, array $references): array
    {
        $ordered = [];
        $visiting = [];

        $visit = function (string $table) use (&$visit, &$ordered, &$visiting, $tables, $references): void {
            if (in_array($table, $ordered, true) || isset($visiting[$table])) {
                return;
            }

            $visiting[$table] = true;

            foreach ($references[$table] ?? [] as $referenced) {
                if (in_array($referenced, $tables, true)) {
                    $visit($referenced);
                }
            }

            $ordered[] = $table;
        };

        foreach ($tables as $table) {
            $visit($table);
        }

        return $ordered;
    }
}
