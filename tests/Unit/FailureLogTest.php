<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\AndroidSdk;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\IosDriver;
use NativePhp\Simulator\Screen;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeDriver;
use Tests\Support\RecordingCommand;

afterEach(function () {
    Configuration::reset();
});

it('copies the ios php log next to the screen', function () {
    $container = sys_get_temp_dir().'/simulator-ios-log-'.uniqid('', true);
    $log = $container.'/Library/Application Support/storage/logs/laravel.log';
    mkdir(dirname($log), 0777, true);
    file_put_contents($log, "SQLSTATE[HY000]\n");
    $directory = sys_get_temp_dir().'/simulator-fail-'.uniqid('', true);
    mkdir($directory);

    Configuration::configure(['bundle_id' => 'com.example.app']);
    $command = new RecordingCommand($container);
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    try {
        expect($driver->captureLogs($directory))->toBe([$directory.'/laravel.log'])
            ->and(file_get_contents($directory.'/laravel.log'))->toBe("SQLSTATE[HY000]\n")
            ->and($command->calls)->toBe([
                ['xcrun', ['simctl', 'get_app_container', 'UDID', 'com.example.app', 'data']],
            ]);
    } finally {
        deleteFixture($container);
        deleteFixture($directory);
    }
});

it('skips a missing ios php log', function () {
    $container = sys_get_temp_dir().'/simulator-ios-log-'.uniqid('', true);
    mkdir($container);
    $directory = sys_get_temp_dir().'/simulator-fail-'.uniqid('', true);
    mkdir($directory);

    Configuration::configure(['bundle_id' => 'com.example.app']);
    $command = new RecordingCommand($container);
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    try {
        expect($driver->captureLogs($directory))->toBe([])
            ->and(is_file($directory.'/laravel.log'))->toBeFalse();
    } finally {
        deleteFixture($container);
        deleteFixture($directory);
    }
});

it('saves the android php log and a logcat slice for the app', function () {
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $command = new RecordingCommand;
    $command->outputs = [
        'app_storage/persisted_data/storage/logs/laravel.log' => "SQLSTATE[HY000]\n",
        'pidof' => "4321\n",
        'logcat' => "process crash\n",
    ];
    $driver = loggedAndroid($command);
    $directory = sys_get_temp_dir().'/simulator-fail-'.uniqid('', true);
    mkdir($directory);
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    try {
        expect($driver->captureLogs($directory))->toBe([
            $directory.'/laravel.log',
            $directory.'/logcat.txt',
        ])
            ->and(file_get_contents($directory.'/laravel.log'))->toBe("SQLSTATE[HY000]\n")
            ->and(file_get_contents($directory.'/logcat.txt'))->toBe("process crash\n")
            ->and($command->calls)->toBe([
                [$adb, ['-s', 'emulator-5554', 'shell', 'run-as', 'com.example.app', 'cat', 'app_storage/persisted_data/storage/logs/laravel.log']],
                [$adb, ['-s', 'emulator-5554', 'shell', 'pidof', 'com.example.app']],
                [$adb, ['-s', 'emulator-5554', 'logcat', '-d', '-t', '400', '--pid=4321']],
            ]);
    } finally {
        deleteFixture($directory);
    }
});

it('filters logcat to the bundle id when the process is gone', function () {
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $command = new RecordingCommand;
    $command->failures = ['pidof' => 'no running process'];
    $command->outputs = [
        'logcat' => "noise\nE AndroidRuntime: Process: com.example.app\nother\n",
    ];
    $driver = loggedAndroid($command);
    $directory = sys_get_temp_dir().'/simulator-fail-'.uniqid('', true);
    mkdir($directory);

    try {
        $driver->captureLogs($directory);

        expect(file_get_contents($directory.'/logcat.txt'))->toBe("E AndroidRuntime: Process: com.example.app\n")
            ->and(is_file($directory.'/laravel.log'))->toBeFalse();
    } finally {
        deleteFixture($directory);
    }
});

it('names the log files in the assertion failure', function () {
    $directory = sys_get_temp_dir().'/simulator-fail-'.uniqid('', true);
    $driver = new FakeDriver([[
        ['label' => 'Home', 'role' => 'Button', 'id' => null, 'center' => [1.0, 1.0]],
    ]]);
    $driver->savedLogs = [
        'laravel.log' => "SQLSTATE[HY000]\n",
        'logcat.txt' => "com.example.app\n",
    ];

    try {
        (new Screen($driver, timeoutSeconds: 0, failureDirectory: $directory))->assertSee('Missing');
        expect(false)->toBeTrue();
    } catch (AssertionFailedError $error) {
        expect($error->getMessage())
            ->toContain($directory.'/tree.json')
            ->toContain($directory.'/screen.png')
            ->toContain($directory.'/laravel.log')
            ->toContain($directory.'/logcat.txt');
    } finally {
        deleteFixture($directory);
    }
});

function loggedAndroid(RecordingCommand $command): AndroidDriver
{
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    return $driver;
}

function deleteFixture(string $path): void
{
    if (is_file($path)) {
        unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        deleteFixture($path.'/'.$entry);
    }

    rmdir($path);
}
