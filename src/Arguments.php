<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class Arguments
{
    private const PLATFORMS = 'NATIVEPHP_SIMULATOR_PLATFORMS';

    private const DEVICES = 'NATIVEPHP_SIMULATOR_DEVICES';

    private const REBUILD = 'NATIVEPHP_SIMULATOR_REBUILD';

    private const WIPE = 'NATIVEPHP_SIMULATOR_WIPE';

    /** @var list<'ios'|'android'>|null */
    private static ?array $platforms = null;

    /** @var list<array{platform: 'ios'|'android'|null, name: string}> */
    private static array $devices = [];

    private static bool $doctor = false;

    private static bool $rebuild = false;

    private static bool $wipe = false;

    private static bool $parsed = false;

    private static bool $excludedByPin = false;

    /**
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    public static function intercept(array $arguments): array
    {
        self::$parsed = true;
        $parsed = self::parse($arguments);

        if ($parsed['platforms'] === [] && $parsed['devices'] === [] && ! $parsed['doctor'] && ! $parsed['rebuild'] && ! $parsed['wipe']) {
            self::hydrate();

            return $parsed['kept'];
        }

        self::$platforms = $parsed['platforms'] === [] ? null : $parsed['platforms'];
        self::$devices = $parsed['devices'];
        self::$doctor = $parsed['doctor'];
        self::$rebuild = $parsed['rebuild'];
        self::$wipe = $parsed['wipe'];
        self::publish();

        return $parsed['kept'];
    }

    public static function wantsDoctor(): bool
    {
        return self::$doctor;
    }

    public static function wantsRebuild(): bool
    {
        return self::$rebuild;
    }

    public static function wantsWipe(): bool
    {
        return self::$wipe;
    }

    /**
     * @param  list<Device>  $devices
     * @return list<Device>
     */
    public static function select(array $devices): array
    {
        if (! self::$parsed) {
            self::intercept($_SERVER['argv'] ?? []);
        }

        if (self::$platforms === null && self::$devices === []) {
            return self::pin($devices);
        }

        $pool = $devices;

        if (self::$platforms !== null) {
            $pool = array_values(array_filter(
                $pool,
                fn (Device $device): bool => in_array($device->platform, self::$platforms, true),
            ));
        }

        if (self::$devices === []) {
            return self::pin($pool);
        }

        $selected = [];

        foreach (self::$devices as $requested) {
            if ($requested['platform'] !== null) {
                if (self::$platforms !== null && ! in_array($requested['platform'], self::$platforms, true)) {
                    continue;
                }

                $selected[$requested['platform'].':'.$requested['name']] = new Device($requested['platform'], $requested['name'], true);

                continue;
            }

            $matches = array_values(array_filter(
                $pool,
                fn (Device $device): bool => $device->name === $requested['name'],
            ));

            if (count($matches) > 1) {
                throw new SimulatorException("More than one device is named [{$requested['name']}]. Prefix it as ios:{$requested['name']} or android:{$requested['name']}.");
            }

            if (count($matches) === 1) {
                $match = $matches[0];
                $selected[$match->platform.':'.$match->name] = new Device($match->platform, $match->name, true);

                continue;
            }

            if ($pool === []) {
                continue;
            }

            $platform = self::overridePlatform($pool);

            if ($platform === null) {
                throw new SimulatorException("Pass --ios or --android with --device={$requested['name']}, or prefix it as ios:{$requested['name']} or android:{$requested['name']}.");
            }

            $selected[$platform.':'.$requested['name']] = new Device($platform, $requested['name'], true);
        }

        return self::pin(self::present(array_values($selected)));
    }

    public static function reset(): void
    {
        self::$platforms = null;
        self::$devices = [];
        self::$doctor = false;
        self::$rebuild = false;
        self::$wipe = false;
        self::$parsed = false;
        self::$excludedByPin = false;
        self::expose(self::PLATFORMS, null);
        self::expose(self::DEVICES, null);
        self::expose(self::REBUILD, null);
        self::expose(self::WIPE, null);
        self::expose(ParallelLanes::ENV, null);
        self::expose(ParallelLanes::DEVICE, null);
        self::expose(ParallelLanes::FOLLOW, null);
    }

    public static function excludedByPin(): bool
    {
        return self::$excludedByPin;
    }

    /**
     * @param  list<Device>  $devices
     * @return list<Device>
     */
    private static function pin(array $devices): array
    {
        $pinned = ParallelLanes::pinned();

        if ($pinned === null) {
            self::$excludedByPin = false;

            return $devices;
        }

        self::expose(ParallelLanes::DEVICE, $pinned);

        $matched = array_values(array_filter(
            $devices,
            fn (Device $device): bool => $device->platform.':'.$device->name === $pinned,
        ));
        self::$excludedByPin = $devices !== [] && $matched === [];

        return $matched;
    }

    /**
     * @param  array<int, string>  $arguments
     * @return array{platforms: list<'ios'|'android'>, devices: list<array{platform: 'ios'|'android'|null, name: string}>, doctor: bool, rebuild: bool, wipe: bool, kept: list<string>}
     */
    private static function parse(array $arguments): array
    {
        // Pest removes its own flags with unset(), which leaves holes in argv.
        $arguments = array_values(array_filter(
            $arguments,
            fn (mixed $argument): bool => is_string($argument),
        ));
        $platforms = [];
        $devices = [];
        $doctor = false;
        $rebuild = false;
        $wipe = false;
        $kept = [];
        $count = count($arguments);

        for ($index = 0; $index < $count; $index++) {
            $argument = $arguments[$index];

            if ($argument === '--simulator-doctor') {
                $doctor = true;

                continue;
            }

            if ($argument === '--rebuild') {
                $rebuild = true;

                continue;
            }

            if ($argument === '--wipe') {
                $wipe = true;

                continue;
            }

            if ($argument === '--ios' || $argument === '--android') {
                $platforms[] = substr($argument, 2);

                continue;
            }

            $name = null;

            if ($argument === '--device') {
                if (! isset($arguments[$index + 1])) {
                    throw new SimulatorException('Pass a device name to --device.');
                }

                $name = $arguments[++$index];
            } elseif (str_starts_with($argument, '--device=')) {
                $name = substr($argument, strlen('--device='));
            }

            if ($name !== null) {
                if ($name === '') {
                    throw new SimulatorException('Pass a device name to --device.');
                }

                $devices[] = self::device($name);

                continue;
            }

            $kept[] = $argument;
        }

        /** @var list<'ios'|'android'> $platforms */
        $platforms = array_values(array_unique($platforms));

        return [
            'platforms' => $platforms,
            'devices' => $devices,
            'doctor' => $doctor,
            'rebuild' => $rebuild,
            'wipe' => $wipe,
            'kept' => $kept,
        ];
    }

    /**
     * @return array{platform: 'ios'|'android'|null, name: string}
     */
    private static function device(string $value): array
    {
        foreach (['ios', 'android'] as $platform) {
            $prefix = $platform.':';

            if (str_starts_with($value, $prefix)) {
                $name = substr($value, strlen($prefix));

                if ($name === '') {
                    throw new SimulatorException('Pass a device name to --device.');
                }

                return ['platform' => $platform, 'name' => $name];
            }
        }

        return ['platform' => null, 'name' => $value];
    }

    /**
     * @param  list<Device>  $pool
     */
    private static function overridePlatform(array $pool): ?string
    {
        if (self::$platforms !== null && count(self::$platforms) === 1) {
            return self::$platforms[0];
        }

        $platforms = array_values(array_unique(array_map(
            fn (Device $device): string => $device->platform,
            $pool,
        )));

        return count($platforms) === 1 ? $platforms[0] : null;
    }

    /**
     * @param  list<Device>  $devices
     * @return list<Device>
     */
    private static function present(array $devices): array
    {
        if ($devices === []) {
            if (self::$platforms !== null) {
                return [];
            }

            throw new SimulatorException('No device matches '.self::requested().'.');
        }

        return $devices;
    }

    private static function requested(): string
    {
        $parts = [];

        foreach (self::$platforms ?? [] as $platform) {
            $parts[] = '--'.$platform;
        }

        foreach (self::$devices as $device) {
            $name = $device['platform'] === null ? $device['name'] : $device['platform'].':'.$device['name'];
            $parts[] = '--device='.$name;
        }

        return $parts === [] ? 'the simulator options' : implode(' ', $parts);
    }

    private static function publish(): void
    {
        self::expose(self::PLATFORMS, self::$platforms === null ? null : implode(',', self::$platforms));
        self::expose(self::DEVICES, self::$devices === [] ? null : json_encode(self::$devices, JSON_THROW_ON_ERROR));
        self::expose(self::REBUILD, self::$rebuild ? '1' : null);
        self::expose(self::WIPE, self::$wipe ? '1' : null);
    }

    /**
     * Symfony Process only forwards variables that live in $_ENV or $_SERVER.
     * putenv() alone never reaches a ParaTest worker.
     */
    private static function expose(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private static function hydrate(): void
    {
        self::$rebuild = getenv(self::REBUILD) === '1';
        self::$wipe = getenv(self::WIPE) === '1';

        $platforms = getenv(self::PLATFORMS);

        if (is_string($platforms) && $platforms !== '') {
            /** @var list<'ios'|'android'> $names */
            $names = array_values(array_filter(
                explode(',', $platforms),
                fn (string $platform): bool => $platform === 'ios' || $platform === 'android',
            ));
            self::$platforms = $names === [] ? null : $names;
        }

        $devices = getenv(self::DEVICES);

        if (! is_string($devices) || $devices === '') {
            return;
        }

        $decoded = json_decode($devices, true);

        if (! is_array($decoded)) {
            return;
        }

        $hydrated = [];

        foreach ($decoded as $device) {
            if (! is_array($device) || ! is_string($device['name'] ?? null) || $device['name'] === '') {
                continue;
            }

            $platform = $device['platform'] ?? null;
            $hydrated[] = [
                'platform' => $platform === 'ios' || $platform === 'android' ? $platform : null,
                'name' => $device['name'],
            ];
        }

        self::$devices = $hydrated;
    }
}
