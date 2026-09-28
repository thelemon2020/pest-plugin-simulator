<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class Platforms
{
    private static ?Machine $machine = null;

    /** @var list<'ios'|'android'> */
    private static array $blocked = [];

    public static function use(Machine $machine): void
    {
        self::$machine = $machine;
        self::$blocked = [];
    }

    public static function block(string $platform): void
    {
        if (($platform === 'ios' || $platform === 'android') && ! in_array($platform, self::$blocked, true)) {
            self::$blocked[] = $platform;
        }
    }

    public static function reset(): void
    {
        self::$machine = null;
        self::$blocked = [];
    }

    /**
     * @return list<'ios'|'android'>
     */
    public static function runnable(): array
    {
        return array_values(array_filter(
            (new Doctor(self::$machine ?? new LocalMachine))->platforms(),
            fn (string $platform): bool => ! in_array($platform, self::$blocked, true),
        ));
    }
}
