<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;
use PDO;

final class SqliteSnapshot
{
    public static function write(PDO $source, string $destination): void
    {
        if (is_file($destination)) {
            unlink($destination);
        }

        $copy = new PDO('sqlite:'.$destination, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $copy->exec('PRAGMA foreign_keys = OFF');
        $copy->exec('PRAGMA synchronous = OFF');
        $copy->exec('PRAGMA journal_mode = MEMORY');

        $objects = self::objects($source);
        $copy->beginTransaction();

        try {
            foreach ($objects as $object) {
                if ($object['type'] === 'table') {
                    $copy->exec($object['sql']);
                }
            }

            foreach ($objects as $object) {
                if ($object['type'] === 'table') {
                    self::copyRows($source, $copy, $object['name']);
                }
            }

            self::copySequence($source, $copy);

            foreach ($objects as $object) {
                if ($object['type'] !== 'table') {
                    $copy->exec($object['sql']);
                }
            }

            $copy->commit();
        } catch (\Throwable $exception) {
            if ($copy->inTransaction()) {
                $copy->rollBack();
            }

            if ($exception instanceof SimulatorException) {
                throw $exception;
            }

            throw new SimulatorException('Could not snapshot the test database: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return list<array{type: string, name: string, sql: string}>
     */
    private static function objects(PDO $source): array
    {
        $statement = $source->query(<<<'SQL'
            SELECT type, name, sql
            FROM sqlite_master
            WHERE sql IS NOT NULL
              AND name NOT LIKE 'sqlite_%'
            ORDER BY CASE type
                WHEN 'table' THEN 0
                WHEN 'index' THEN 1
                WHEN 'trigger' THEN 2
                WHEN 'view' THEN 3
                ELSE 4
            END
            SQL);

        if ($statement === false) {
            throw new SimulatorException('Could not read the test database.');
        }

        $fetched = [];

        while (($object = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $fetched[] = $object;
        }

        $virtual = [];

        foreach ($fetched as $object) {
            if (self::isVirtual($object['sql'])) {
                $virtual[] = $object['name'];
            }
        }

        $objects = [];

        foreach ($fetched as $object) {
            if (self::isVirtual($object['sql']) || self::isShadow($object['name'], $virtual)) {
                continue;
            }

            $objects[] = $object;
        }

        return $objects;
    }

    private static function isVirtual(string $sql): bool
    {
        return str_starts_with(strtoupper(ltrim($sql)), 'CREATE VIRTUAL');
    }

    /**
     * @param  list<string>  $virtual
     */
    private static function isShadow(string $name, array $virtual): bool
    {
        $suffixes = ['data', 'idx', 'content', 'docsize', 'config', 'segments', 'segdir', 'stat', 'node', 'parent', 'rowid'];

        foreach ($virtual as $table) {
            $prefix = $table.'_';

            if (str_starts_with($name, $prefix) && in_array(substr($name, strlen($prefix)), $suffixes, true)) {
                return true;
            }
        }

        return false;
    }

    private static function copyRows(PDO $source, PDO $destination, string $table): void
    {
        $quoted = self::quote($table);
        $select = $source->query('SELECT * FROM '.$quoted);

        if ($select === false) {
            throw new SimulatorException("Could not read [{$table}] from the test database.");
        }

        $skip = self::generatedColumns($source, $table);
        $insert = null;
        $names = [];

        while (($row = $select->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = array_diff_key($row, $skip);

            if ($insert === null) {
                $names = array_keys($row);

                if ($names === []) {
                    return;
                }

                $columns = array_map(self::quote(...), $names);
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $insert = $destination->prepare(
                    'INSERT INTO '.$quoted.' ('.implode(', ', $columns).') VALUES ('.$placeholders.')',
                );
            }

            $values = [];

            foreach ($names as $name) {
                $values[] = $row[$name];
            }

            $insert->execute($values);
        }
    }

    /**
     * @return array<string, true>
     */
    private static function generatedColumns(PDO $source, string $table): array
    {
        $info = $source->query('PRAGMA table_xinfo('.self::quote($table).')');

        if ($info === false) {
            return [];
        }

        $skip = [];

        while (($column = $info->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ((int) ($column['hidden'] ?? 0) >= 2) {
                $skip[$column['name']] = true;
            }
        }

        return $skip;
    }

    private static function copySequence(PDO $source, PDO $destination): void
    {
        $exists = $source->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'");

        if ($exists === false || $exists->fetchColumn() === false) {
            return;
        }

        $rows = $source->query('SELECT name, seq FROM sqlite_sequence');

        if ($rows === false) {
            return;
        }

        $update = $destination->prepare('UPDATE sqlite_sequence SET seq = ? WHERE name = ?');
        $insert = $destination->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)');

        while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            $update->execute([$row['seq'], $row['name']]);

            if ($update->rowCount() === 0) {
                $insert->execute([$row['name'], $row['seq']]);
            }
        }
    }

    private static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
