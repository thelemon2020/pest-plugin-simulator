<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;
use NativePhp\Simulator\Exceptions\SimulatorException;

final class TestDatabase
{
    private static bool $published = false;

    private static ?Closure $snapshot = null;

    private static ?Closure $connection = null;

    public static function begin(): void
    {
        self::$published = false;
    }

    public static function end(): void
    {
        self::$published = false;
        self::$connection = null;
    }

    public static function fake(?Closure $snapshot): void
    {
        self::$snapshot = $snapshot;
    }

    public static function connection(?Closure $connection): void
    {
        self::$connection = $connection;
    }

    public static function publish(Driver $driver): void
    {
        if (self::$published) {
            return;
        }

        $path = self::snapshot();

        if ($path === null) {
            return;
        }

        try {
            $driver->installDatabase($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        self::$published = true;
    }

    private static function snapshot(): ?string
    {
        if (self::$snapshot !== null) {
            $path = (self::$snapshot)();

            return is_string($path) ? $path : null;
        }

        $connection = self::host();

        if ($connection === null) {
            return null;
        }

        if ($connection->getDriverName() !== 'sqlite') {
            throw new SimulatorException('The simulator can only load a SQLite test database. Set DB_CONNECTION=sqlite.');
        }

        $database = $connection->getDatabaseName();
        self::requireFile(is_string($database) ? $database : '');

        $path = tempnam(sys_get_temp_dir(), 'simulator-');

        if ($path === false) {
            throw new SimulatorException('Could not create a database snapshot.');
        }

        try {
            SqliteSnapshot::write($connection->getPdo(), $path);
        } catch (\Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }

            throw $exception;
        }

        return $path;
    }

    private static function host(): ?object
    {
        if (self::$connection !== null) {
            $connection = (self::$connection)();

            return is_object($connection) ? $connection : null;
        }

        if (! class_exists(\Illuminate\Support\Facades\DB::class)) {
            return null;
        }

        return \Illuminate\Support\Facades\DB::connection();
    }

    private static function requireFile(string $database): void
    {
        $database = trim($database);

        if ($database === '' || $database === ':memory:' || str_ends_with($database, ':memory:')) {
            throw new SimulatorException('The simulator snapshot needs a file-backed SQLite database.');
        }
    }
}
