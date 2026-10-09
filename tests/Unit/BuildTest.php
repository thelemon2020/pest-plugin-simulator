<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\AndroidSdk;
use NativePhp\Simulator\Arguments;
use NativePhp\Simulator\Command;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\IosDriver;
use Tests\Support\RecordingCommand;

beforeEach(function () {
    Arguments::reset();
    Configuration::configure(['bundle_id' => 'com.example.app']);
    resetBuilds();
});

afterEach(function () {
    Arguments::reset();
    Configuration::reset();
    resetBuilds();
});

it('keeps an installed ios debug app without building or launching', function () {
    $command = new RecordingCommand;
    $command->outputs = [
        'get_app_container' => "/tmp/NativePHP-simulator.app\n",
        '--entitlements' => "<key>get-task-allow</key><true/>",
    ];
    $driver = iosBuildDriver($command);

    buildOnce($driver);

    expect($command->calls)->toBe([
        ['xcrun', ['simctl', 'get_app_container', 'UDID', 'com.example.app', 'app']],
        ['codesign', ['-d', '--entitlements', '-', '/tmp/NativePHP-simulator.app']],
    ]);

    buildOnce($driver);

    expect($command->calls)->toHaveCount(2);
});

it('checks the ios debug app again in a new process without launching it', function () {
    $command = new RecordingCommand;
    $command->outputs = [
        'get_app_container' => "/tmp/NativePHP-simulator.app\n",
        '--entitlements' => "<key>get-task-allow</key><true/>",
    ];
    $driver = iosBuildDriver($command);

    buildOnce($driver);
    resetBuilds();
    buildOnce($driver);

    expect(array_column($command->calls, 0))->toBe(['xcrun', 'codesign', 'xcrun', 'codesign']);
});

it('builds ios when the installed app is not a debug binary', function () {
    $command = new RecordingCommand;
    $command->outputs = [
        'get_app_container' => "/tmp/NativePHP.app\n",
        '--entitlements' => "<key>get-task-allow</key><false/>",
    ];
    $driver = iosBuildDriver($command);

    buildOnce($driver);

    expect($command->calls[2])->toBe([
        'php',
        ['artisan', 'native:run', 'ios', 'UDID', '--build=debug', '--no-tty'],
    ]);
});

it('builds ios when the debug app is not installed', function () {
    $command = new RecordingCommand;
    $command->failures = ['get_app_container' => 'No such file or directory'];
    $driver = iosBuildDriver($command);

    buildOnce($driver);

    expect($command->calls)->toBe([
        ['xcrun', ['simctl', 'get_app_container', 'UDID', 'com.example.app', 'app']],
        ['php', ['artisan', 'native:run', 'ios', 'UDID', '--build=debug', '--no-tty']],
    ])->and($command->timeouts['php artisan native:run ios UDID --build=debug --no-tty'])->toBe((float) Command::BUILD_TIMEOUT);
});

it('rebuilds ios when asked', function () {
    Arguments::intercept(['--rebuild']);
    $command = new RecordingCommand;
    $command->outputs = ['get_app_container' => "/tmp/NativePHP-simulator.app\n"];
    $driver = iosBuildDriver($command);

    buildOnce($driver);

    expect($command->calls)->toBe([
        ['php', ['artisan', 'native:run', 'ios', 'UDID', '--build=debug', '--no-tty']],
    ]);
});

it('keeps an installed android debug app without building or launching', function () {
    $command = new RecordingCommand;
    $command->outputs = [
        'path' => "package:/data/app/com.example.app/base.apk\n",
        'dumpsys' => 'flags=[ DEBUGGABLE HAS_CODE ]',
    ];
    $driver = androidBuildDriver($command);
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    buildOnce($driver);

    expect($command->calls)->toBe([
        [$adb, ['-s', 'emulator-5554', 'shell', 'pm', 'path', 'com.example.app']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'dumpsys', 'package', 'com.example.app']],
    ]);

    buildOnce($driver);

    expect($command->calls)->toHaveCount(2);
});

it('builds android when the installed app is not debuggable', function () {
    $command = new RecordingCommand;
    $command->outputs = [
        'path' => "package:/data/app/com.example.app/base.apk\n",
        'dumpsys' => 'flags=[ HAS_CODE ]',
    ];
    $driver = androidBuildDriver($command);

    buildOnce($driver);

    expect($command->calls[2])->toBe([
        'php',
        ['artisan', 'native:run', 'android', 'emulator-5554', '--build=debug', '--no-tty'],
    ]);
});

it('builds android when nothing is installed', function () {
    $command = new RecordingCommand;
    $command->failures = ['path' => 'Unknown package'];
    $driver = androidBuildDriver($command);

    buildOnce($driver);

    expect($command->calls)->toBe([
        [AndroidSdk::binary('platform-tools/adb', 'adb'), ['-s', 'emulator-5554', 'shell', 'pm', 'path', 'com.example.app']],
        ['php', ['artisan', 'native:run', 'android', 'emulator-5554', '--build=debug', '--no-tty']],
    ]);
});

it('rebuilds android when asked', function () {
    Arguments::intercept(['--rebuild']);
    $command = new RecordingCommand;
    $command->outputs = [
        'path' => "package:/data/app/com.example.app/base.apk\n",
        'dumpsys' => 'flags=[ DEBUGGABLE HAS_CODE ]',
    ];
    $driver = androidBuildDriver($command);

    buildOnce($driver);

    expect($command->calls)->toBe([
        ['php', ['artisan', 'native:run', 'android', 'emulator-5554', '--build=debug', '--no-tty']],
    ]);
});

function iosBuildDriver(RecordingCommand $command): IosDriver
{
    $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    return $driver;
}

function androidBuildDriver(RecordingCommand $command): AndroidDriver
{
    $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    return $driver;
}

function buildOnce(IosDriver|AndroidDriver $driver): void
{
    (new ReflectionMethod($driver, 'buildOnce'))->invoke($driver);
}

function resetBuilds(): void
{
    (new ReflectionProperty(IosDriver::class, 'built'))->setValue(null, []);
    (new ReflectionProperty(AndroidDriver::class, 'built'))->setValue(null, []);
}
