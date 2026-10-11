<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;
use Throwable;

/**
 * The appearance, text size, location, and status bar a test asks the device for. Like
 * Permissions, each test is compared with what the device holds: a setting an earlier test
 * changed and this one does not ask for is put back, and one both ask for is left alone.
 */
final class DeviceState
{
    /**
     * About the same scale on both platforms: an iOS content size category, and an Android
     * font scale. iOS's default is `large`, and its body text is 14, 17, 19, 23, and 33
     * points across these.
     *
     * @var array<string, array{ios: string, android: string}>
     */
    private const TEXT_SIZES = [
        'small' => ['ios' => 'extra-small', 'android' => '0.85'],
        'default' => ['ios' => 'large', 'android' => '1.0'],
        'large' => ['ios' => 'extra-large', 'android' => '1.15'],
        'larger' => ['ios' => 'extra-extra-extra-large', 'android' => '1.3'],
        'largest' => ['ios' => 'accessibility-large', 'android' => '2.0'],
    ];

    /**
     * What this run set on each device, by device key and setting.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $held = [];

    /**
     * What this test asked for, by setting.
     *
     * @var array<string, mixed>
     */
    private static array $wanted = [];

    /**
     * The devices this test has brought in line with $wanted. Until then a request waits
     * for screen().
     *
     * @var array<string, true>
     */
    private static array $applied = [];

    /**
     * @return 'light'|'dark'
     */
    public static function appearance(string $mode): string
    {
        if (! in_array($mode, ['light', 'dark'], true)) {
            throw new SimulatorException("Unknown appearance [{$mode}]. Choose light or dark.");
        }

        return $mode;
    }

    public static function size(string $size): string
    {
        if (! isset(self::TEXT_SIZES[$size])) {
            $known = implode(', ', array_keys(self::TEXT_SIZES));

            throw new SimulatorException("Unknown text size [{$size}]. Choose from {$known}.");
        }

        return $size;
    }

    /**
     * The platform's own value for a text size: a content size category on iOS, a font
     * scale on Android.
     */
    public static function textSize(string $platform, string $size): string
    {
        return self::TEXT_SIZES[self::size($size)][$platform]
            ?? throw new SimulatorException("Unknown platform [{$platform}].");
    }

    /**
     * @return array{0: float, 1: float}
     */
    public static function coordinates(float $latitude, float $longitude): array
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new SimulatorException("[{$latitude}, {$longitude}] is not a latitude and longitude.");
        }

        return [$latitude, $longitude];
    }

    /**
     * A coordinate for a command line, without exponents or trailing zeros. Six places are
     * about ten centimetres.
     */
    public static function number(float $value): string
    {
        $number = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $number === '-0' ? '0' : $number;
    }

    /**
     * Asks for a setting for the rest of this test. Before the test's first screen() it
     * waits for that screen. After it, the device changes now.
     */
    public static function request(string $setting, mixed $value): void
    {
        self::$wanted[$setting] = $value;

        if (! Run::inside()) {
            return;
        }

        $device = Run::device();

        if (isset(self::$applied[$device->key()])) {
            self::sync(Sessions::get($device), $device->key());
        }
    }

    /**
     * Changes a setting now, from a screen that is already open.
     */
    public static function change(Driver $driver, string $deviceKey, string $setting, mixed $value): void
    {
        self::$wanted[$setting] = $value;
        self::$applied[$deviceKey] = true;
        self::sync($driver, $deviceKey);
    }

    public static function apply(Driver $driver, string $deviceKey): void
    {
        if (isset(self::$applied[$deviceKey])) {
            return;
        }

        self::$applied[$deviceKey] = true;
        self::sync($driver, $deviceKey);
    }

    /**
     * Readying a device shuts the others of its platform down, and a Simulator keeps its
     * appearance and text size through that. So put back what this run changed on them
     * first, while they are still up.
     */
    public static function arrive(Device $device): void
    {
        foreach (array_keys(self::$held) as $key) {
            if ($key === $device->key() || Device::fromKey($key)->platform !== $device->platform) {
                continue;
            }

            self::restore(Sessions::get(Device::fromKey($key)), $key);
        }
    }

    public static function beginTest(): void
    {
        self::$applied = [];
    }

    public static function forgetRequest(): void
    {
        self::$wanted = [];
    }

    public static function reset(): void
    {
        self::$held = [];
        self::$wanted = [];
        self::$applied = [];
    }

    private static function sync(Driver $driver, string $deviceKey): void
    {
        foreach (self::backFirst(self::$held[$deviceKey] ?? []) as $setting) {
            if (! array_key_exists($setting, self::$wanted)) {
                self::send($driver, $setting, null);
                unset(self::$held[$deviceKey][$setting]);
            }
        }

        foreach (self::$wanted as $setting => $value) {
            $held = self::$held[$deviceKey] ?? [];

            if (array_key_exists($setting, $held) && $held[$setting] === $value) {
                continue;
            }

            if ($held === []) {
                self::deferRestore($driver, $deviceKey);
            }

            self::send($driver, $setting, $value);
            self::$held[$deviceKey][$setting] = $value;
        }
    }

    /**
     * Puts the device back when the run ends. A new task each time the device starts
     * holding something: one deferred before the device last booted would run after the
     * task that shuts it down.
     */
    private static function deferRestore(Driver $driver, string $deviceKey): void
    {
        Shutdown::defer(static function () use ($driver, $deviceKey): void {
            self::restore($driver, $deviceKey);
        });
    }

    private static function restore(Driver $driver, string $deviceKey): void
    {
        $held = self::$held[$deviceKey] ?? [];
        unset(self::$held[$deviceKey]);

        foreach (self::backFirst($held) as $setting) {
            try {
                self::send($driver, $setting, null);
            } catch (Throwable) {
            }
        }
    }

    /**
     * The settings to put back, the status bar first: Android fixes a fixed status bar again
     * after an appearance or text size change, which is wasted on one about to be let go.
     *
     * @param  array<string, mixed>  $held
     * @return list<string>
     */
    private static function backFirst(array $held): array
    {
        $settings = array_keys($held);
        usort($settings, fn (string $left, string $right): int => ($right === 'statusBar') <=> ($left === 'statusBar'));

        return $settings;
    }

    /**
     * @param  mixed  $value  null puts the device's own setting back
     */
    private static function send(Driver $driver, string $setting, mixed $value): void
    {
        match ($setting) {
            'appearance' => $driver->appearance(is_string($value) ? $value : null),
            'textSize' => $driver->textSize(is_string($value) ? $value : null),
            'location' => $driver->location(is_array($value) ? [(float) $value[0], (float) $value[1]] : null),
            'statusBar' => $driver->statusBar($value === true),
            default => throw new SimulatorException("Unknown device setting [{$setting}]."),
        };
    }
}
