<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;

final class SuiteRegistration
{
    /** @var list<Device>|null */
    private static ?array $devices = null;

    /** @var array<string, Device> */
    private static array $registered = [];

    /**
     * @param  list<Device>  $devices
     */
    public static function run(array $devices, Closure $tests): void
    {
        foreach ($devices as $device) {
            self::$registered[$device->identity()] = $device;
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
     * Devices from every mobile() registration in this process.
     *
     * @return list<Device>
     */
    public static function registeredDevices(): array
    {
        return array_values(self::$registered);
    }

    public static function forgetRegisteredDevices(): void
    {
        self::$registered = [];
    }
}
