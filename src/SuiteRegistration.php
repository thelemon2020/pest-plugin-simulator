<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;

final class SuiteRegistration
{
    /** @var list<Device>|null */
    private static ?array $devices = null;

    /**
     * @param  list<Device>  $devices
     */
    public static function run(array $devices, Closure $tests): void
    {
        $previous = self::$devices;
        self::$devices = $devices;

        try {
            $tests();
        } finally {
            self::$devices = $previous;
        }
    }

    public static function active(): bool
    {
        return self::$devices !== null;
    }

    /**
     * @return list<Device>
     */
    public static function devices(): array
    {
        return self::$devices ?? [];
    }
}
