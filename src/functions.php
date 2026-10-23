<?php

declare(strict_types=1);

use NativePhp\Simulator\AppLog;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\DeviceDatabase;
use NativePhp\Simulator\DeviceState;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Fakes\FakeSync;
use NativePhp\Simulator\MobileSuite;
use NativePhp\Simulator\Permissions;
use NativePhp\Simulator\Photo;
use NativePhp\Simulator\Recording;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Screen;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\SystemUi;
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
 * Errors the app logs during this test do not fail it.
 */
function allowAppErrors(): void
{
    AppLog::allow();
}

/**
 * The app logs this exception, or one that extends it, during this test. That error does
 * not fail the test, and the test fails if the app never logs it.
 *
 * @param  class-string|string  $class
 */
function expectAppError(string $class): void
{
    AppLog::expect($class);
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
 * `portrait` or `landscape` for this test.
 */
function rotate(string $orientation): void
{
    DeviceState::request('orientation', DeviceState::orientation($orientation));
}

/**
 * A status bar that reads 9:41 with full signal and battery for this test.
 */
function fixStatusBar(): void
{
    DeviceState::request('statusBar', true);
}

/**
 * Adds an image or video to the device's photo library before the first screen(), for
 * pickPhoto() to choose.
 */
function addPhoto(string $path): void
{
    if (! Run::inside()) {
        throw new SimulatorException('addPhoto() only works inside a mobile() suite.');
    }

    $driver = Sessions::get(Run::device());
    $driver->ensureReady();
    $driver->addMedia(Photo::file($path));
}

/**
 * Makes sure the device has a face or finger enrolled before the first screen(), for an
 * app that checks before it offers Face ID or a fingerprint.
 */
function enrollBiometrics(): void
{
    if (! Run::inside()) {
        throw new SimulatorException('enrollBiometrics() only works inside a mobile() suite.');
    }

    $driver = Sessions::get(Run::device());
    $driver->ensureReady();
    $driver->biometric('enroll');
}

/**
 * The app's database as the device has it now, opened with writes turned off. One pull
 * serves until the next Screen step. Call it after a step that waited for the app, such as
 * assertSee(), so what the app wrote has landed.
 */
function deviceDatabase(): PDO
{
    if (! Run::inside()) {
        throw new SimulatorException('deviceDatabase() only works inside a mobile() suite.');
    }

    // Before the test's first screen() the device still has the last test's database.
    if (Trace::steps() === []) {
        throw new SimulatorException('deviceDatabase() reads what the app wrote, so call it after screen().');
    }

    $driver = Sessions::get(Run::device());
    $driver->ensureReady();

    return DeviceDatabase::handOut($driver);
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
    SystemUi::close($driver);
    Recording::begin($driver, $device);
    TestDatabase::publish($driver);
    FakeSync::sync($driver);
    AppLog::start($driver);
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
    // The database was copied in and the route has mounted, so an earlier pull is stale.
    DeviceDatabase::forget();

    return new Screen(
        $driver,
        timeoutSeconds: $configuration->timeout(),
        firstLaunchSeconds: $driver->freshlyInstalled() ? Screen::FIRST_LAUNCH_SECONDS : 0.0,
    );
}
