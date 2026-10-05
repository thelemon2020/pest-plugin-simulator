<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;

final class SuiteRegistration
{
    /** @var list<Device>|null */
    private static ?array $devices = null;

    /** @var array<string, Device> */
    private static array $seen = [];

    /**
     * @param  list<Device>  $devices
     */
    public static function run(array $devices, Closure $tests): void
    {
        foreach ($devices as $device) {
            self::$seen[$device->platform.':'.$device->name] = $device;
        }

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

    /**
     * @return list<Device>
     */
    public static function seen(): array
    {
        return array_values(self::$seen);
    }

    public static function forgetSeen(): void
    {
        self::$seen = [];
    }
}
