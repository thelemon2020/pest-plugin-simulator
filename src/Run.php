<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class Run
{
    private static ?Device $device = null;

    public static function useDevice(Device $device): void
    {
        self::$device = $device;
    }

    public static function inside(): bool
    {
        return self::$device !== null;
    }

    public static function device(): Device
    {
        return self::$device ?? throw new Exceptions\SimulatorException('screen() only works inside a mobile() suite.');
    }

    public static function clear(): void
    {
        self::$device = null;
    }
}
