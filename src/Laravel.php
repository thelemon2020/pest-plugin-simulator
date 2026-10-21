<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Illuminate\Container\Container;

/**
 * Whether the tests booted a Laravel app. Laravel is not a dependency of the plugin, and
 * its helpers load with Composer before any app is booted, so a function or a class being
 * there says nothing.
 */
final class Laravel
{
    public static function booted(): bool
    {
        return class_exists(Container::class) && Container::getInstance()->bound('config');
    }
}
