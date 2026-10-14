<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

/**
 * A test that fails with a photo picker, a permission dialog, an alert, or a share sheet
 * open leaves it there for the next one. A test's first screen() closes whatever is left
 * before it opens the app.
 */
final class SystemUi
{
    private static bool $closed = false;

    public static function beginTest(): void
    {
        self::$closed = false;
    }

    public static function close(Driver $driver): void
    {
        if (self::$closed) {
            return;
        }

        self::$closed = true;
        $driver->closeSystemUi(TestDatabase::stopsApp());
    }
}
