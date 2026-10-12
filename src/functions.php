<?php

declare(strict_types=1);

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\DeviceState;
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

/**
 * This test does not copy the test database into the app. The app keeps the database it
 * has and is not stopped, so the first screen() skips a cold launch.
 */
function keepDatabase(): void
{
    TestDatabase::keep();
}

/**
 * `light` or `dark` for this test, from its first screen(), or from now after that.
 */
function appearance(string $mode): void
{
    DeviceState::request('appearance', DeviceState::appearance($mode));
}

/**
 * small, default, large, larger, or largest for this test.
 */
function textSize(string $size): void
{
    DeviceState::request('textSize', DeviceState::size($size));
}

/**
 * A simulated location for this test: latitude, then longitude.
 */
function location(float $latitude, float $longitude): void
{
    DeviceState::request('location', DeviceState::coordinates($latitude, $longitude));
}

/**
 * A status bar that reads 9:41 with full signal and battery for this test.
 */
function fixStatusBar(): void
{
    DeviceState::request('statusBar', true);
}

function record(?string $path = null): void
{
    if (! Run::inside()) {
        throw new SimulatorException('record() only works inside a mobile() suite.');
    }

    $device = Run::device();
    Recording::request($path === '' ? null : $path);
    $driver = Sessions::get($device);
    DeviceState::arrive($device);
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
    DeviceState::arrive($device);
    $driver->ensureReady();
    Recording::begin($driver, $device);
    TestDatabase::publish($driver);
    Permissions::apply($driver, $device->key());
    DeviceState::apply($driver, $device->key());
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
