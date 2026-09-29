<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\AndroidSdk;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\IosDriver;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Screen;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\TestDatabase;
use Tests\Support\FakeDriver;
use Tests\Support\RecordingCommand;

beforeEach(function () {
    $this->driver = new FakeDriver([]);
    $this->database = tempnam(sys_get_temp_dir(), 'simulator-');
    $this->videoDirectory = sys_get_temp_dir().'/simulator-record-'.uniqid('', true);
    $this->video = $this->videoDirectory.'/signup.mp4';
    $this->recorded = false;
    $this->stops = 1;
    Configuration::configure(['scheme' => 'myapp']);
    Sessions::fake($this->driver);
    TestDatabase::fake(fn (): string => $this->database);
});

afterEach(function () {
    if ($this->recorded) {
        $stops = array_values(array_filter(
            $this->driver->events,
            fn (array $event): bool => ($event[0] ?? null) === 'record-stop',
        ));

        expect($stops)->toHaveCount($this->stops);
    }

    Sessions::fake(null);
    TestDatabase::fake(null);
    Configuration::reset();

    if (is_string($this->database) && is_file($this->database)) {
        unlink($this->database);
    }

    if (is_string($this->video) && is_file($this->video)) {
        unlink($this->video);
    }

    if (is_string($this->videoDirectory) && is_dir($this->videoDirectory)) {
        rmdir($this->videoDirectory);
    }

    $recordings = getcwd().'/simulator-recordings';

    if (is_string($recordings) && is_dir($recordings)) {
        array_map(unlink(...), glob($recordings.'/*') ?: []);
        rmdir($recordings);
    }
});

mobile(function () {
    it('records from before the screen opens until the test ends', function () {
        $this->recorded = true;
        permissions([]);
        record($this->video);

        screen('/lights');

        expect(is_dir($this->videoDirectory))->toBeTrue()
            ->and($this->driver->recording)->toBe($this->video)
            ->and($this->driver->events)->toBe([
                ['ready'],
                ['record', $this->video],
                ['ready'],
                ['install', $this->database],
                ['open', 'myapp://lights'],
            ]);
    });

    it('keeps one recording across screens', function () {
        $this->recorded = true;
        permissions([]);
        record($this->video);
        record($this->video);

        screen('/lights');
        screen('/settings');

        expect(array_values(array_filter(
            $this->driver->events,
            fn (array $event): bool => $event[0] === 'record',
        )))->toBe([['record', $this->video]]);
    });

    it('names the video after the test and the device', function () {
        $this->recorded = true;
        permissions([]);
        record();

        $device = Run::device();
        $name = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $device->name), '-'));

        expect($this->driver->recording)->toMatch(
            '#/simulator-recordings/[a-z0-9-]+-'.$device->platform.'-'.$name.'\\.mp4$#'
        )->and(is_dir(getcwd().'/simulator-recordings'))->toBeTrue();
    });

    it('stops the recording when the test fails', function () {
        $this->recorded = true;
        permissions([]);
        record($this->video);
        screen('/lights');

        throw new RuntimeException('broken');
    })->throws(RuntimeException::class);

    it('records from the screen until stopRecord', function () {
        $this->recorded = true;
        permissions([]);

        $screen = screen('/lights')
            ->record($this->video)
            ->stopRecord();

        expect($screen)->toBeInstanceOf(Screen::class)
            ->and($this->driver->events)->toBe([
                ['ready'],
                ['install', $this->database],
                ['open', 'myapp://lights'],
                ['record', $this->video],
                ['record-stop'],
            ]);
    });

    it('stops a recording started before the screen', function () {
        $this->recorded = true;
        permissions([]);
        record($this->video);

        screen('/lights')->stopRecord();

        expect(array_values(array_filter(
            $this->driver->events,
            fn (array $event): bool => ($event[0] ?? null) === 'record-stop',
        )))->toHaveCount(1);
    });

    it('records again after stopping', function () {
        $this->recorded = true;
        $this->stops = 2;
        permissions([]);
        $second = $this->videoDirectory.'/again.mp4';

        screen('/lights')->record($this->video)->stopRecord();
        screen('/settings')->record($second);

        expect($this->driver->recording)->toBe($second);
    });

    it('refuses to stop when nothing is recording', function () {
        permissions([]);
        screen('/lights');
        stopRecord();
    })->throws(SimulatorException::class, 'No recording is in progress.');

    it('refuses to stop the screen when nothing is recording', function () {
        permissions([]);
        screen('/lights')->stopRecord();
    })->throws(SimulatorException::class, 'No recording is in progress.');
});

it('refuses record outside a mobile suite', function () {
    record();
})->throws(SimulatorException::class, 'record() only works inside a mobile() suite.');

it('refuses stopRecord outside a mobile suite', function () {
    stopRecord();
})->throws(SimulatorException::class, 'stopRecord() only works inside a mobile() suite.');

it('refuses a screen recording outside a mobile suite', function () {
    $screen = new Screen(new FakeDriver([]));

    expect(fn () => $screen->record())->toThrow(SimulatorException::class, 'record() only works inside a mobile() suite.')
        ->and(fn () => $screen->stopRecord())->toThrow(SimulatorException::class, 'stopRecord() only works inside a mobile() suite.');
});

it('records the ios simulator until interrupted', function () {
    $command = new RecordingCommand;
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');
    $path = sys_get_temp_dir().'/ios-recording.mp4';

    $driver->startRecording($path);
    $driver->stopRecording();

    expect($command->calls)->toBe([
        ['xcrun', ['simctl', 'io', 'UDID', 'recordVideo', '--codec=h264', '--force', $path]],
        ['interrupt', ['4242']],
    ]);
});

it('pulls the android recording after stopping screenrecord', function () {
    $command = new RecordingCommand;
    $command->outputs = ['pidof' => "4321\n"];
    $path = sys_get_temp_dir().'/android-recording.mp4';
    $driver = androidRecordingDriver($command);
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    $driver->startRecording($path);
    $driver->stopRecording();

    expect($command->calls)->toBe([
        [$adb, ['-s', 'emulator-5554', 'shell', 'screenrecord', '--time-limit', '180', '/sdcard/pest-simulator-recording.mp4']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'pidof', 'screenrecord']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'kill', '-INT', '4321']],
        [$adb, ['-s', 'emulator-5554', 'pull', '/sdcard/pest-simulator-recording.mp4', $path]],
        [$adb, ['-s', 'emulator-5554', 'shell', 'rm', '-f', '/sdcard/pest-simulator-recording.mp4']],
    ]);
});

it('still pulls the android recording when screenrecord has already exited', function () {
    $command = new RecordingCommand;
    $command->failures = ['pidof' => 'no process'];
    $path = sys_get_temp_dir().'/android-recording.mp4';
    $driver = androidRecordingDriver($command);

    $driver->startRecording($path);
    $driver->stopRecording();

    expect(array_column($command->calls, 1))->toContain(
        ['-s', 'emulator-5554', 'pull', '/sdcard/pest-simulator-recording.mp4', $path],
    )->and(array_column($command->calls, 1))->not->toContain(
        ['-s', 'emulator-5554', 'shell', 'kill', '-INT', '4321'],
    );
});

function androidRecordingDriver(RecordingCommand $command): AndroidDriver
{
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    return $driver;
}
