<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;
use NativePhp\Simulator\Exceptions\SimulatorException;

final class TestDatabase
{
    private static bool $published = false;

    private static ?Closure $snapshot = null;

    public static function begin(): void
    {
        self::$published = false;
    }

    public static function end(): void
    {
        self::$published = false;
    }

    public static function fake(?Closure $snapshot): void
    {
        self::$snapshot = $snapshot;
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

        if (! class_exists(\Illuminate\Support\Facades\DB::class)) {
            return null;
        }

        $connection = \Illuminate\Support\Facades\DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            throw new SimulatorException('The simulator can only load a SQLite test database. Set DB_CONNECTION=sqlite.');
        }

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
}
