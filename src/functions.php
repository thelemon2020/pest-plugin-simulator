<?php

declare(strict_types=1);

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\MobileSuite;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Screen;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\TestDatabase;

function mobile(Closure $tests): MobileSuite
{
    return new MobileSuite($tests);
}

function screen(string $path): Screen
{
    $device = Run::device();
    $configuration = Configuration::resolve();
    $driver = Sessions::get($device);
    $driver->ensureReady();
    TestDatabase::publish($driver);
    $driver->open($configuration->urlFor($path));

    return new Screen($driver, timeoutSeconds: $configuration->timeout());
}
