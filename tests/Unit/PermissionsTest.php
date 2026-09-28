<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\AndroidSdk;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\IosDriver;
use NativePhp\Simulator\Permissions;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\TestDatabase;
use Tests\Support\FakeDriver;
use Tests\Support\RecordingCommand;

afterEach(function () {
    Configuration::reset();
    Sessions::fake(null);
    Run::clear();
});

it('grants the ios services simctl knows', function () {
    $command = new RecordingCommand;
    $driver = iosPermissionsDriver($command);

    $driver->grant(['camera', 'photos', 'location', 'notifications', 'contacts']);

    expect($command->calls)->toBe([
        ['xcrun', ['simctl', 'privacy', 'UDID', 'grant', 'microphone', 'com.example.app']],
        ['xcrun', ['simctl', 'privacy', 'UDID', 'grant', 'photos', 'com.example.app']],
        ['xcrun', ['simctl', 'privacy', 'UDID', 'grant', 'media-library', 'com.example.app']],
        ['xcrun', ['simctl', 'privacy', 'UDID', 'grant', 'location', 'com.example.app']],
        ['xcrun', ['simctl', 'privacy', 'UDID', 'grant', 'contacts', 'com.example.app']],
    ]);
});

it('grants the android permissions the facades declare', function () {
    $command = new RecordingCommand;
    $driver = androidPermissionsDriver($command);
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    $driver->grant(['camera', 'photos', 'location', 'notifications', 'contacts']);

    expect($command->calls)->toBe([
        [$adb, ['-s', 'emulator-5554', 'shell', 'pm', 'grant', 'com.example.app', 'android.permission.CAMERA']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'pm', 'grant', 'com.example.app', 'android.permission.RECORD_AUDIO']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'pm', 'grant', 'com.example.app', 'android.permission.READ_MEDIA_IMAGES']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'pm', 'grant', 'com.example.app', 'android.permission.READ_MEDIA_VIDEO']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'pm', 'grant', 'com.example.app', 'android.permission.READ_MEDIA_AUDIO']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'pm', 'grant', 'com.example.app', 'android.permission.ACCESS_MEDIA_LOCATION']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'pm', 'grant', 'com.example.app', 'android.permission.ACCESS_COARSE_LOCATION']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'pm', 'grant', 'com.example.app', 'android.permission.ACCESS_FINE_LOCATION']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'pm', 'grant', 'com.example.app', 'android.permission.POST_NOTIFICATIONS']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'pm', 'grant', 'com.example.app', 'android.permission.READ_CONTACTS']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'pm', 'grant', 'com.example.app', 'android.permission.WRITE_CONTACTS']],
    ]);
});

it('skips an android permission the app did not declare', function () {
    $command = new RecordingCommand;
    $command->failures = [
        'android.permission.CAMERA' => 'Package com.example.app has not requested permission android.permission.CAMERA',
    ];
    $driver = androidPermissionsDriver($command);

    $driver->grant(['camera', 'notifications']);

    expect(array_column($command->calls, 1))->toHaveCount(3)
        ->and($command->calls[2][1][6])->toBe('android.permission.POST_NOTIFICATIONS');
});

it('stops when a grant fails for another reason', function () {
    $command = new RecordingCommand;
    $command->failures = ['android.permission.CAMERA' => 'device offline'];
    $driver = androidPermissionsDriver($command);

    expect(fn () => $driver->grant(['camera']))->toThrow(SimulatorException::class, 'device offline');
});

it('grants a smaller set once before the screen opens', function () {
    Configuration::configure([
        'scheme' => 'myapp',
        'bundle_id' => 'com.example.app',
        'permissions' => ['location', 'contacts'],
    ]);
    $driver = new FakeDriver([]);
    Sessions::fake($driver);
    Run::useDevice(new Device('ios', 'iPhone', true));
    TestDatabase::fake(null);
    permissions(['camera', 'photos']);

    screen('/lights');
    screen('/again');

    expect($driver->grants)->toBe([['camera', 'photos']])
        ->and($driver->opened)->toBe(['myapp://lights', 'myapp://again']);
});

it('grants nothing when the test opts out', function () {
    $driver = new FakeDriver([]);

    permissions([]);
    Permissions::apply($driver, 'ios:1:iPhone');

    expect($driver->grants)->toBe([[]]);
});

it('rejects an unknown permission', function () {
    permissions(['calendar']);
})->throws(SimulatorException::class, 'camera, photos, location, notifications, contacts');

function iosPermissionsDriver(RecordingCommand $command): IosDriver
{
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    return $driver;
}

function androidPermissionsDriver(RecordingCommand $command): AndroidDriver
{
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    return $driver;
}
