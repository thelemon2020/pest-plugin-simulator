<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class Permissions
{
    /**
     * Camera::recordVideo() asks for the microphone. simctl privacy has no camera service.
     * LocalNotifications::requestPermission() has no simctl service either.
     *
     * @var array<string, list<string>>
     */
    private const IOS = [
        'camera' => ['microphone'],
        'photos' => ['photos', 'media-library'],
        'location' => ['location'],
        'notifications' => [],
        'contacts' => ['contacts'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ANDROID = [
        'camera' => [
            'android.permission.CAMERA',
            'android.permission.RECORD_AUDIO',
        ],
        'photos' => [
            'android.permission.READ_MEDIA_IMAGES',
            'android.permission.READ_MEDIA_VIDEO',
            'android.permission.READ_MEDIA_AUDIO',
            'android.permission.ACCESS_MEDIA_LOCATION',
        ],
        'location' => [
            'android.permission.ACCESS_COARSE_LOCATION',
            'android.permission.ACCESS_FINE_LOCATION',
        ],
        'notifications' => [
            'android.permission.POST_NOTIFICATIONS',
        ],
        'contacts' => [
            'android.permission.READ_CONTACTS',
            'android.permission.WRITE_CONTACTS',
        ],
    ];

    /** @var array<string, list<string>> */
    private static array $held = [];

    /** @var array<string, true> */
    private static array $applied = [];

    /** @var list<string>|null */
    private static ?array $only = null;

    /**
     * @param  list<string>  $services
     */
    public static function only(array $services): void
    {
        $services = array_values(array_unique($services));
        self::assertKnown($services);
        self::$only = $services;
    }

    public static function forgetRequest(): void
    {
        self::$only = null;
    }

    public static function beginTest(): void
    {
        self::$applied = [];
    }

    public static function reset(): void
    {
        self::$held = [];
        self::$applied = [];
        self::$only = null;
    }

    public static function apply(Driver $driver, string $deviceKey): void
    {
        if (isset(self::$applied[$deviceKey])) {
            return;
        }

        $services = array_values(self::$only ?? Configuration::resolve()->permissions() ?? array_keys(self::IOS));
        self::assertKnown($services);
        $previous = self::$held[$deviceKey] ?? [];
        self::$applied[$deviceKey] = true;
        self::$only = null;

        if (self::same($previous, $services)) {
            self::$held[$deviceKey] = $services;

            return;
        }

        $revoke = array_values(array_diff($previous, $services));
        $grant = array_values(array_diff($services, $previous));

        if ($revoke !== []) {
            $driver->revoke($revoke);
        }

        if ($grant !== []) {
            $driver->grant($grant);
        }

        self::$held[$deviceKey] = $services;
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private static function same(array $left, array $right): bool
    {
        sort($left);
        sort($right);

        return $left === $right;
    }

    /**
     * @param  list<string>  $services
     * @return list<string>
     */
    public static function targets(string $platform, array $services): array
    {
        self::assertKnown($services);
        $map = match ($platform) {
            'ios' => self::IOS,
            'android' => self::ANDROID,
            default => throw new SimulatorException("Unknown platform [{$platform}]."),
        };
        $targets = [];

        foreach ($services as $service) {
            foreach ($map[$service] as $target) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    public static function attempt(callable $command): void
    {
        try {
            $command();
        } catch (SimulatorException $exception) {
            if (! self::skipped($exception->getMessage())) {
                throw $exception;
            }
        }
    }

    public static function skipped(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'has not requested permission')
            || str_contains($message, 'unknown permission')
            || str_contains($message, 'not a changeable permission');
    }

    /**
     * @param  list<string>  $services
     */
    private static function assertKnown(array $services): void
    {
        foreach ($services as $service) {
            if (! isset(self::IOS[$service])) {
                $known = implode(', ', array_keys(self::IOS));

                throw new SimulatorException("Unknown permission [{$service}]. Choose from {$known}.");
            }
        }
    }
}
