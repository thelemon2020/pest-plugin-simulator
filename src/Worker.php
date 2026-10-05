<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class Worker
{
    public static function index(): int
    {
        $token = getenv('TEST_TOKEN');

        if (! is_string($token) || ! ctype_digit($token)) {
            return 0;
        }

        return (int) $token;
    }

    public static function parallel(): bool
    {
        $token = getenv('TEST_TOKEN');

        return is_string($token) && ctype_digit($token);
    }

    public static function grpcPort(): int
    {
        if (! self::parallel()) {
            return 10882;
        }

        return 10883 + self::index();
    }

    public static function emulatorPort(): int
    {
        return 5556 + (self::index() * 2);
    }

    public static function nameSuffix(): string
    {
        // Stable across runs so a parallel worker boots the Simulator it
        // cloned last time instead of copying a new one.
        return 'pest-'.self::index();
    }
}
