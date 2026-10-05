<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class Env
{
    public static function get(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? $value : null;
    }

    /**
     * Symfony Process only forwards variables that live in $_ENV or $_SERVER.
     * putenv() alone never reaches a ParaTest worker.
     */
    public static function set(string $key, ?string $value): void
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
}
