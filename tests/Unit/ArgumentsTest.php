<?php

declare(strict_types=1);

use NativePhp\Simulator\Arguments;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Plugin;

afterEach(function () {
    Arguments::reset();
});

it('strips simulator options before phpunit sees them', function () {
    $remaining = (new Plugin)->handleArguments([
        'vendor/bin/pest',
        '--ios',
        '--device=iPhone 17 Pro',
        'tests/Feature/LightsTest.php',
    ]);

    expect($remaining)->toBe([
        'vendor/bin/pest',
        'tests/Feature/LightsTest.php',
    ]);
});

it('limits a run to one platform', function () {
    Arguments::intercept(['--ios']);

    $devices = Arguments::select([
        new Device('ios', 'iPhone Latest', false),
        new Device('android', 'Pixel Default', false),
    ]);

    expect(array_map(fn (Device $device): string => $device->key(), $devices))->toBe([
        'ios:0:iPhone Latest',
    ]);
});

it('names a device on the selected platform', function () {
    Arguments::intercept(['--ios', '--device', 'iPhone 17 Pro']);

    $devices = Arguments::select([
        new Device('ios', 'iPhone Latest', false),
        new Device('android', 'Pixel Default', false),
    ]);

    expect(array_map(fn (Device $device): string => $device->key(), $devices))->toBe([
        'ios:1:iPhone 17 Pro',
    ]);
});

it('pins each platform from a prefixed device name', function () {
    Arguments::intercept(['--device=ios:iPhone 17 Pro', '--device=android:Pixel 8']);

    $devices = Arguments::select([
        new Device('ios', 'iPhone Latest', false),
        new Device('android', 'Pixel Default', false),
    ]);

    expect(array_map(fn (Device $device): string => $device->name, $devices))->toBe([
        'iPhone 17 Pro',
        'Pixel 8',
    ]);
});

it('asks for a platform when the device name is not in the suite', function () {
    Arguments::intercept(['--device=iPhone 17 Pro']);

    Arguments::select([
        new Device('ios', 'iPhone Latest', false),
        new Device('android', 'Pixel Default', false),
    ]);
})->throws(SimulatorException::class);

it('keeps the selection for a worker process', function () {
    Arguments::intercept(['--android', '--device=Pixel 8']);
    $platforms = getenv('NATIVEPHP_SIMULATOR_PLATFORMS');
    $devices = getenv('NATIVEPHP_SIMULATOR_DEVICES');

    Arguments::reset();
    putenv('NATIVEPHP_SIMULATOR_PLATFORMS='.$platforms);
    putenv('NATIVEPHP_SIMULATOR_DEVICES='.$devices);

    Arguments::intercept(['vendor/bin/pest']);

    $selected = Arguments::select([
        new Device('ios', 'iPhone Latest', false),
        new Device('android', 'Pixel Default', false),
    ]);

    expect(array_map(fn (Device $device): string => $device->key(), $selected))->toBe([
        'android:1:Pixel 8',
    ]);
});

it('records a doctor request', function () {
    Arguments::intercept(['--simulator-doctor']);

    expect(Arguments::wantsDoctor())->toBeTrue();
});

it('strips a rebuild request and keeps it for a worker', function () {
    $remaining = Arguments::intercept(['vendor/bin/pest', '--rebuild', 'tests/Feature/LightsTest.php']);

    expect($remaining)->toBe([
        'vendor/bin/pest',
        'tests/Feature/LightsTest.php',
    ])->and(Arguments::wantsRebuild())->toBeTrue();

    $rebuild = getenv('NATIVEPHP_SIMULATOR_REBUILD');
    Arguments::reset();
    putenv('NATIVEPHP_SIMULATOR_REBUILD='.$rebuild);
    Arguments::intercept(['vendor/bin/pest']);

    expect(Arguments::wantsRebuild())->toBeTrue();
});
