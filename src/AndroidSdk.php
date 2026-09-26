<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class AndroidSdk
{
    public static function home(): string
    {
        $home = getenv('ANDROID_HOME') ?: getenv('ANDROID_SDK_ROOT') ?: '';

        if ($home !== '' && is_dir($home)) {
            return $home;
        }

        $default = getenv('HOME').'/Library/Android/sdk';

        return is_dir($default) ? $default : '';
    }

    public static function binary(string $relative, string $fallback): string
    {
        $home = self::home();
        $path = $home === '' ? '' : $home.'/'.$relative;

        if ($path !== '' && is_executable($path)) {
            return $path;
        }

        return $fallback;
    }
}
