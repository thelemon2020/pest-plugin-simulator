<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class DevicePlan
{
    /**
     * @param  list<string>|null  $ios
     * @param  list<string>|null  $android
     * @return list<Device>
     */
    public static function resolve(
        bool $iosTouched,
        ?array $ios,
        bool $androidTouched,
        ?array $android,
        string $latestIphone,
        string $defaultAvd,
    ): array {
        if (! $iosTouched && ! $androidTouched) {
            return [
                new Device('ios', $latestIphone, false),
                new Device('android', $defaultAvd, false),
            ];
        }

        $devices = [];

        if ($iosTouched) {
            foreach ($ios ?? [$latestIphone] as $name) {
                $devices[] = new Device('ios', $name, $ios !== null);
            }
        }

        if ($androidTouched) {
            foreach ($android ?? [$defaultAvd] as $name) {
                $devices[] = new Device('android', $name, $android !== null);
            }
        }

        if ($devices === []) {
            throw new SimulatorException('A mobile suite needs at least one device.');
        }

        return $devices;
    }

    /**
     * @param  list<Device>  $devices
     * @param  list<string>  $platforms
     * @return list<Device>
     */
    public static function filter(array $devices, array $platforms): array
    {
        if ($platforms === []) {
            return $devices;
        }

        return array_values(array_filter(
            $devices,
            fn (Device $device): bool => in_array($device->platform, $platforms, true),
        ));
    }
}
