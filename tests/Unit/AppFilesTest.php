<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\AndroidSdk;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\IosDriver;
use Tests\Support\FakeDriver;
use Tests\Support\RecordingCommand;

beforeEach(function () {
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $this->local = sys_get_temp_dir().'/simulator-app-file-'.uniqid('', true);
});

afterEach(function () {
    Configuration::reset();

    foreach ([$this->local, $this->local.'-out'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

function androidWith(RecordingCommand $command): AndroidDriver
{
    $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    return $driver;
}

it('writes a file into the ios app container without stopping the app, and reads it back', function () {
    $container = sys_get_temp_dir().'/simulator-ios-'.uniqid('', true);
    mkdir($container);
    $bytes = random_bytes(64)."\r\n\0";
    file_put_contents($this->local, $bytes);
    $command = new RecordingCommand($container);
    $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    try {
        $driver->pushAppFile($this->local, 'storage/framework/pest-simulator/fakes');

        expect(file_get_contents($container.'/Library/Application Support/storage/framework/pest-simulator/fakes'))->toBe($bytes)
            ->and(glob($container.'/Library/Application Support/storage/framework/pest-simulator/*'))->toHaveCount(1)
            ->and($driver->pullAppFile('storage/framework/pest-simulator/fakes', $this->local.'-out'))->toBeTrue()
            ->and(file_get_contents($this->local.'-out'))->toBe($bytes)
            ->and(array_column($command->calls, 1))->not->toContain(['simctl', 'terminate', 'UDID', 'com.example.app']);

        $driver->removeAppFile('storage/framework/pest-simulator/fakes');
        $driver->removeAppFile('storage/framework/pest-simulator/fakes');

        expect(is_file($container.'/Library/Application Support/storage/framework/pest-simulator/fakes'))->toBeFalse()
            ->and($driver->pullAppFile('storage/framework/pest-simulator/fakes', $this->local.'-out'))->toBeFalse();
    } finally {
        exec('rm -rf '.escapeshellarg($container));
    }
});

it('leaves no temp file in the ios app container when a write cannot land', function () {
    $container = sys_get_temp_dir().'/simulator-ios-'.uniqid('', true);
    $directory = $container.'/Library/Application Support/storage/app';
    mkdir($directory.'/taken', 0777, true);
    file_put_contents($this->local, 'one');
    $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), new RecordingCommand($container));
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    // PHPUnit reports the warning rename() raises even under @.
    set_error_handler(fn (): bool => true);

    try {
        // A directory in the way makes the rename fail after the copy landed.
        expect(fn () => $driver->pushAppFile($this->local, 'storage/app/taken'))
            ->toThrow(SimulatorException::class, 'Could not copy')
            ->and(glob($directory.'/*.pest-*'))->toBe([]);
    } finally {
        restore_error_handler();
        exec('rm -rf '.escapeshellarg($container));
    }
});

it('reads nothing from an ios app that has no container', function () {
    $command = new RecordingCommand;
    $command->failures = ['get_app_container' => 'No such app'];
    $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    expect($driver->pullAppFile('storage/logs/laravel.log', $this->local))->toBeFalse()
        ->and(is_file($this->local))->toBeFalse();
});

it('pushes a file into the android app through a temp file it moves into place', function () {
    file_put_contents($this->local, 'fakes');
    $command = new RecordingCommand;
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    androidWith($command)->pushAppFile($this->local, 'storage/framework/pest-simulator/fakes');

    $remote = $command->calls[0][1][4];
    $shell = $command->calls[1][1][3];
    $path = 'app_storage/persisted_data/storage/framework/pest-simulator/fakes';

    expect($command->calls)->toHaveCount(2)
        ->and($command->calls[0])->toBe([$adb, ['-s', 'emulator-5554', 'push', $this->local, $remote]])
        ->and($remote)->toStartWith('/data/local/tmp/pest-simulator-')
        ->and($shell)->toContain("run-as 'com.example.app' mkdir -p 'app_storage/persisted_data/storage/framework/pest-simulator'")
        ->and($shell)->toMatch("#run-as 'com\\.example\\.app' cp '{$remote}' '{$path}\\.pest-([0-9a-f]{8})' && run-as 'com\\.example\\.app' mv -f '{$path}\\.pest-\\1' '{$path}'#")
        ->and($shell)->toEndWith("rm -f '{$remote}'; exit \$status");
});

it('cleans up the temp file when a push into the android app fails', function () {
    file_put_contents($this->local, 'fakes');
    $command = new RecordingCommand;
    $command->responder = function (string $binary, array $arguments): ?string {
        if (($arguments[2] ?? null) === 'shell' && str_contains($arguments[3] ?? '', 'chmod')) {
            throw new SimulatorException('run-as: package not debuggable');
        }

        return null;
    };

    expect(fn () => androidWith($command)->pushAppFile($this->local, 'storage/framework/pest-simulator/fakes'))
        ->toThrow(SimulatorException::class, 'run-as: package not debuggable');

    $remote = $command->calls[0][1][4];
    $script = $command->calls[1][1][3];

    expect(end($command->calls)[1])->toBe(['-s', 'emulator-5554', 'shell', 'rm', '-f', $remote])
        // The copy inside the app is removed by the same shell when a step after it fails.
        ->and($script)->toMatch("#\\[ \\\$status -eq 0 \\] \\|\\| run-as 'com\\.example\\.app' rm -f 'app_storage/persisted_data/storage/framework/pest-simulator/fakes\\.pest-[0-9a-f]{8}'#");
});

it('pulls a file out of the android app byte for byte', function () {
    $bytes = "SQLite format 3\0\r\n".random_bytes(32);
    $command = new RecordingCommand;
    $command->outputs = ['exec-out' => 'Y'.$bytes];

    expect(androidWith($command)->pullAppFile('database/database.sqlite', $this->local))->toBeTrue()
        ->and(file_get_contents($this->local))->toBe($bytes)
        ->and($command->calls[0][1])->toBe(['-s', 'emulator-5554', 'exec-out', "run-as 'com.example.app' sh -c 'if [ -f '\\''app_storage/persisted_data/database/database.sqlite'\\'' ]; then printf Y; cat '\\''app_storage/persisted_data/database/database.sqlite'\\''; else printf N; fi'"]);
});

it('tells a missing android file from an empty one', function () {
    $command = new RecordingCommand;
    $command->outputs = ['exec-out' => 'N'];
    $driver = androidWith($command);

    expect($driver->pullAppFile('database/database.sqlite-wal', $this->local))->toBeFalse()
        ->and(is_file($this->local))->toBeFalse();

    $command->outputs = ['exec-out' => 'Y'];

    expect($driver->pullAppFile('database/database.sqlite-wal', $this->local))->toBeTrue()
        ->and(file_get_contents($this->local))->toBe('');
});

it('reads nothing from an android app run-as cannot enter', function () {
    $command = new RecordingCommand;
    $command->failures = ['exec-out' => 'run-as: package not debuggable'];

    expect(androidWith($command)->pullAppFile('storage/logs/laravel.log', $this->local))->toBeFalse();
});

it('removes a file from the android app', function () {
    $command = new RecordingCommand;

    androidWith($command)->removeAppFile('storage/framework/pest-simulator/fakes');

    expect($command->calls[0][1])->toBe(['-s', 'emulator-5554', 'shell', "run-as 'com.example.app' rm -f 'app_storage/persisted_data/storage/framework/pest-simulator/fakes'"]);
});

it('quotes a path the device shell would split or expand', function () {
    $command = new RecordingCommand;

    androidWith($command)->removeAppFile('storage/app/my file;*.txt');

    expect($command->calls[0][1])->toBe(['-s', 'emulator-5554', 'shell', "run-as 'com.example.app' rm -f 'app_storage/persisted_data/storage/app/my file;*.txt'"]);
});

it('quotes a path with a space when it pushes into the android app', function () {
    $command = new RecordingCommand;
    file_put_contents($this->local, 'one');

    androidWith($command)->pushAppFile($this->local, 'storage/app/my file.txt');

    expect($command->calls[1][1][3])->toContain("mv -f 'app_storage/persisted_data/storage/app/my file.txt.pest-")
        ->and($command->calls[1][1][3])->toContain("' 'app_storage/persisted_data/storage/app/my file.txt'");
});

it('keeps the fake driver\'s app files in memory', function () {
    $driver = new FakeDriver([[]]);
    file_put_contents($this->local, 'one');

    $driver->pushAppFile($this->local, 'a/b');

    expect($driver->appFiles)->toBe(['a/b' => 'one'])
        ->and($driver->pullAppFile('a/b', $this->local.'-out'))->toBeTrue()
        ->and(file_get_contents($this->local.'-out'))->toBe('one')
        ->and($driver->pullAppFile('a/c', $this->local.'-out'))->toBeFalse();

    $driver->removeAppFile('a/b');

    expect($driver->appFiles)->toBe([]);
});
