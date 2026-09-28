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

    /** @var array<string, true> */
    private static array $granted = [];

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

    public static function reset(): void
    {
        self::$granted = [];
        self::$only = null;
    }

    public static function apply(Driver $driver, string $deviceKey): void
    {
        if (isset(self::$granted[$deviceKey])) {
            return;
        }

        $services = self::$only ?? Configuration::resolve()->permissions() ?? array_keys(self::IOS);
        self::assertKnown($services);
        $driver->grant(array_values($services));
        self::$granted[$deviceKey] = true;
        self::$only = null;
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
