<?php

declare(strict_types=1);

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\MobileSuite;
use NativePhp\Simulator\Permissions;
use NativePhp\Simulator\Recording;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Screen;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\TestDatabase;
use NativePhp\Simulator\Trace;

function mobile(Closure $tests): MobileSuite
{
    return new MobileSuite($tests);
}

/**
 * @param  list<string>  $services
 */
function permissions(array $services): void
{
    Permissions::only($services);
}

function record(?string $path = null): void
{
    if (! Run::inside()) {
        throw new SimulatorException('record() only works inside a mobile() suite.');
    }

    $device = Run::device();
    Recording::request($path === '' ? null : $path);
    $driver = Sessions::get($device);
    $driver->ensureReady();
    Recording::begin($driver, $device);
}

function stopRecord(): void
{
    if (! Run::inside()) {
        throw new SimulatorException('stopRecord() only works inside a mobile() suite.');
    }

    Recording::stop();
}

function screen(string $path): Screen
{
    $started = microtime(true);
    $device = Run::device();
    $configuration = Configuration::resolve();
    $driver = Sessions::get($device);
    $driver->ensureReady();
    Recording::begin($driver, $device);
    TestDatabase::publish($driver);
    Permissions::apply($driver, $device->key());
    $url = $configuration->urlFor($path);
    $step = ['action' => 'open', 'target' => $url, 'started' => $started];

    try {
        $driver->open($url);
    } catch (Throwable $exception) {
        Trace::add($step + ['seconds' => microtime(true) - $started, 'result' => 'error', 'error' => $exception->getMessage()]);

        throw $exception;
    }

    Trace::add($step + ['seconds' => microtime(true) - $started, 'result' => 'ok']);

    return new Screen($driver, timeoutSeconds: $configuration->timeout());
}
