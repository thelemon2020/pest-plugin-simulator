<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class SimulatorList
{
    /**
     * @return list<array{name: string, udid: string, state: string, runtime: string}>
     */
    public static function devices(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $devices = [];

        foreach ($decoded['devices'] ?? [] as $runtime => $entries) {
            if (! is_array($entries) || ! is_string($runtime)) {
                continue;
            }

            foreach ($entries as $device) {
                if (! is_array($device) || ($device['isAvailable'] ?? true) === false) {
                    continue;
                }

                if (! is_string($device['name'] ?? null) || ! is_string($device['udid'] ?? null)) {
                    continue;
                }

                $devices[] = [
                    'name' => $device['name'],
                    'udid' => $device['udid'],
                    'state' => is_string($device['state'] ?? null) ? $device['state'] : '',
                    'runtime' => $runtime,
                ];
            }
        }

        return $devices;
    }

    public static function latestIphone(string $json): string
    {
        $iphones = array_values(array_filter(
            self::devices($json),
            fn (array $device): bool => str_starts_with($device['name'], 'iPhone'),
        ));

        if ($iphones === []) {
            throw new SimulatorException('No iPhone Simulator is installed.');
        }

        usort($iphones, function (array $left, array $right): int {
            $runtime = self::runtimeVersion($right['runtime']) <=> self::runtimeVersion($left['runtime']);

            if ($runtime !== 0) {
                return $runtime;
            }

            $version = self::nameVersion($right['name']) <=> self::nameVersion($left['name']);

            return $version !== 0 ? $version : strcmp($right['name'], $left['name']);
        });

        return $iphones[0]['name'];
    }

    /**
     * @return list<array{name: string, udid: string}>
     */
    public static function booted(string $json): array
    {
        $booted = [];

        foreach (self::devices($json) as $device) {
            if ($device['state'] === 'Booted') {
                $booted[] = ['name' => $device['name'], 'udid' => $device['udid']];
            }
        }

        return $booted;
    }

    public static function udidFor(string $json, string $name): string
    {
        foreach (self::devices($json) as $device) {
            if ($device['name'] === $name) {
                return $device['udid'];
            }
        }

        throw new SimulatorException("No Simulator named [{$name}] is installed.");
    }

    private static function runtimeVersion(string $runtime): int
    {
        if (preg_match('/iOS-(\d+)-(\d+)/', $runtime, $matches) !== 1) {
            return 0;
        }

        return ((int) $matches[1]) * 100 + (int) $matches[2];
    }

    private static function nameVersion(string $name): int
    {
        if (preg_match('/(\d+)/', $name, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }
}
