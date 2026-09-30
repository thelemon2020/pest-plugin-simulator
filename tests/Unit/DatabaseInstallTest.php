<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\AndroidSdk;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\IosDriver;
use Tests\Support\RecordingCommand;

it('copies a sqlite file into the ios app container', function () {
    $container = sys_get_temp_dir().'/simulator-ios-'.uniqid('', true);
    $database = $container.'/Library/Application Support/database/database.sqlite';
    mkdir(dirname($database), 0777, true);
    file_put_contents($database, 'old');
    file_put_contents($database.'-wal', 'wal');
    file_put_contents($database.'-shm', 'shm');

    $source = sys_get_temp_dir().'/simulator-source-'.uniqid('', true).'.sqlite';
    $bytes = random_bytes(32);
    file_put_contents($source, $bytes);

    Configuration::configure(['bundle_id' => 'com.example.app']);
    $command = new RecordingCommand($container);
    $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    try {
        $driver->installDatabase($source);

        expect(file_get_contents($database))->toBe($bytes)
            ->and(is_file($database.'-wal'))->toBeFalse()
            ->and(is_file($database.'-shm'))->toBeFalse()
            ->and($command->calls)->toBe([
                ['xcrun', ['simctl', 'terminate', 'UDID', 'com.example.app']],
                ['xcrun', ['simctl', 'get_app_container', 'UDID', 'com.example.app', 'data']],
            ]);
    } finally {
        unlink($source);
        removeTree($container);
    }
});

it('creates the ios database directory', function () {
    $container = sys_get_temp_dir().'/simulator-ios-'.uniqid('', true);
    mkdir($container);
    $database = $container.'/Library/Application Support/database/database.sqlite';
    $source = sys_get_temp_dir().'/simulator-source-'.uniqid('', true).'.sqlite';
    $bytes = random_bytes(32);
    file_put_contents($source, $bytes);

    Configuration::configure(['bundle_id' => 'com.example.app']);
    $command = new RecordingCommand($container);
    $command->failures = ['terminate' => 'No such process'];
    $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    try {
        $driver->installDatabase($source);

        expect(file_get_contents($database))->toBe($bytes);
    } finally {
        unlink($source);
        removeTree($container);
    }
});

it('creates the ios database directory when simctl phrases it as nothing to terminate', function () {
    // The real-world message on newer Xcode/simctl, distinct from "No such process" above —
    // this is the one that shipped broken: every test failed on a first install until this
    // wording was recognised too.
    $container = sys_get_temp_dir().'/simulator-ios-'.uniqid('', true);
    mkdir($container);
    $database = $container.'/Library/Application Support/database/database.sqlite';
    $source = sys_get_temp_dir().'/simulator-source-'.uniqid('', true).'.sqlite';
    $bytes = random_bytes(32);
    file_put_contents($source, $bytes);

    Configuration::configure(['bundle_id' => 'com.example.app']);
    $command = new RecordingCommand($container);
    $command->failures = ['terminate' => 'Simulator device failed to terminate com.example.app. found nothing to terminate'];
    $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    try {
        $driver->installDatabase($source);

        expect(file_get_contents($database))->toBe($bytes);
    } finally {
        unlink($source);
        removeTree($container);
    }
});

it('stops installing when the ios app cannot be quit', function () {
    $container = sys_get_temp_dir().'/simulator-ios-'.uniqid('', true);
    mkdir($container);
    $source = sys_get_temp_dir().'/simulator-source-'.uniqid('', true).'.sqlite';
    file_put_contents($source, 'db');

    Configuration::configure(['bundle_id' => 'com.example.app']);
    $command = new RecordingCommand($container);
    $command->failures = ['terminate' => 'Unable to connect to the simulator'];
    $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    try {
        expect(fn () => $driver->installDatabase($source))->toThrow(SimulatorException::class);
        expect($command->calls)->toHaveCount(1)
            ->and(is_file($container.'/Library/Application Support/database/database.sqlite'))->toBeFalse();
    } finally {
        unlink($source);
        removeTree($container);
    }
});

it('pushes a sqlite file onto the android emulator', function () {
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $command = new RecordingCommand;
    $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    $source = sys_get_temp_dir().'/simulator-source-'.uniqid('', true).'.sqlite';
    file_put_contents($source, 'db');
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');
    $database = 'app_storage/persisted_data/database/database.sqlite';

    try {
        $driver->installDatabase($source);
        $first = remoteDatabase($command->calls);
        $command->calls = [];
        $driver->installDatabase($source);
        $second = remoteDatabase($command->calls);

        expect($first)->not->toBe($second)
            ->and($first)->toStartWith('/data/local/tmp/pest-simulator-')
            ->and($command->calls)->toBe([
                [$adb, ['-s', 'emulator-5554', 'shell', 'am', 'force-stop', 'com.example.app']],
                [$adb, ['-s', 'emulator-5554', 'push', $source, $second]],
                [$adb, ['-s', 'emulator-5554', 'shell', 'chmod', '644', $second]],
                [$adb, ['-s', 'emulator-5554', 'shell', 'run-as', 'com.example.app', 'mkdir', '-p', 'app_storage/persisted_data/database']],
                [$adb, ['-s', 'emulator-5554', 'shell', 'run-as', 'com.example.app', 'cp', $second, $database]],
                [$adb, ['-s', 'emulator-5554', 'shell', 'run-as', 'com.example.app', 'rm', '-f', $database.'-wal', $database.'-shm']],
                [$adb, ['-s', 'emulator-5554', 'shell', 'rm', '-f', $second]],
            ]);
    } finally {
        unlink($source);
    }
});

it('stops installing when the android app cannot be quit', function () {
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $command = new RecordingCommand;
    $command->failures = ['force-stop' => 'device offline'];
    $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');
    $source = sys_get_temp_dir().'/simulator-source-'.uniqid('', true).'.sqlite';
    file_put_contents($source, 'db');

    try {
        expect(fn () => $driver->installDatabase($source))->toThrow(SimulatorException::class);
        expect($command->calls)->toHaveCount(1);
    } finally {
        unlink($source);
    }
});

function remoteDatabase(array $calls): string
{
    foreach ($calls as $call) {
        if (($call[1][2] ?? null) === 'push') {
            return $call[1][4];
        }
    }

    throw new RuntimeException('The install did not push a database.');
}

function removeTree(string $path): void
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

        removeTree($path.'/'.$entry);
    }

    rmdir($path);
}
