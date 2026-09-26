<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class Sessions
{
    /** @var array<string, Driver> */
    private static array $drivers = [];

    private static ?Driver $fake = null;

    public static function fake(?Driver $driver): void
    {
        self::$fake = $driver;
    }

    public static function get(Device $device): Driver
    {
        if (self::$fake !== null) {
            return self::$fake;
        }

        $key = $device->key();

        if (isset(self::$drivers[$key])) {
            return self::$drivers[$key];
        }

        $configuration = Configuration::resolve();

        $driver = match ($device->platform) {
            'ios' => new IosDriver($device, $configuration),
            'android' => new AndroidDriver($device, $configuration),
            default => throw new SimulatorException("Unknown platform [{$device->platform}]."),
        };

        return self::$drivers[$key] = $driver;
    }
}
