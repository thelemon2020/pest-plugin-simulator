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
use Tests\Support\FakeDriver;
use Tests\Support\RecordingCommand;

beforeEach(function () {
    $this->photo = sys_get_temp_dir().'/pest-simulator-photo-'.uniqid('', true).'.jpg';
    file_put_contents($this->photo, 'jpeg');
});

afterEach(function () {
    @unlink($this->photo);
    Configuration::reset();
    Sessions::fake(null);
    Run::clear();
});

function inputIosDriver(RecordingCommand $command): IosDriver
{
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    return $driver;
}

function inputAndroidDriver(RecordingCommand $command): AndroidDriver
{
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    return $driver;
}

it('adds a photo to the library from the screen', function () {
    $driver = new FakeDriver([[]]);

    (new Screen($driver, timeoutSeconds: 0))->addPhoto($this->photo);

    expect($driver->events)->toBe([['addMedia', $this->photo]]);
});

it('adds a photo before the first screen', function () {
    $driver = new FakeDriver([[]]);
    Sessions::fake($driver);
    Run::useDevice(new Device('ios', 'iPhone', true));

    addPhoto($this->photo);

    expect($driver->events)->toBe([['ready'], ['addMedia', $this->photo]]);
});

it('refuses a photo that is not there', function () {
    expect(fn () => (new Screen(new FakeDriver([[]]), timeoutSeconds: 0))->addPhoto('missing/cat.jpg'))
        ->toThrow(SimulatorException::class, 'addPhoto() could not find [missing/cat.jpg].');
});

it('refuses a file that is not an image or video', function () {
    $notes = sys_get_temp_dir().'/pest-simulator-notes-'.uniqid('', true).'.txt';
    file_put_contents($notes, 'notes');

    try {
        expect(fn () => (new Screen(new FakeDriver([[]]), timeoutSeconds: 0))->addPhoto($notes))
            ->toThrow(SimulatorException::class, 'addPhoto() takes an image or video');
    } finally {
        unlink($notes);
    }
});

it('refuses addPhoto() outside a mobile suite', function () {
    expect(fn () => addPhoto($this->photo))->toThrow(SimulatorException::class, 'addPhoto() only works inside a mobile() suite.');
});

it('answers a biometric prompt from the screen', function () {
    $driver = new FakeDriver([[]]);

    (new Screen($driver, timeoutSeconds: 0))->enrollBiometrics()->matchBiometric()->failBiometric();

    expect($driver->events)->toBe([['biometric', 'enroll'], ['biometric', 'match'], ['biometric', 'fail']]);
});

it('enrolls biometrics before the first screen', function () {
    $driver = new FakeDriver([[]]);
    Sessions::fake($driver);
    Run::useDevice(new Device('ios', 'iPhone', true));

    enrollBiometrics();

    expect($driver->events)->toBe([['ready'], ['biometric', 'enroll']]);
});

it('delivers a push notification with a title, body, and data', function () {
    $driver = new FakeDriver([[]]);

    (new Screen($driver, timeoutSeconds: 0))
        ->push('Order shipped', 'It arrives Tuesday', ['order' => 42])
        ->push('Ping');

    expect($driver->pushes)->toBe([
        ['aps' => ['alert' => ['title' => 'Order shipped', 'body' => 'It arrives Tuesday'], 'sound' => 'default'], 'order' => 42],
        ['aps' => ['alert' => ['title' => 'Ping'], 'sound' => 'default']],
    ]);
});

it('adds media on ios with simctl', function () {
    $command = new RecordingCommand;

    inputIosDriver($command)->addMedia('/tmp/cat.jpg');

    expect($command->calls)->toBe([['xcrun', ['simctl', 'addmedia', 'UDID', '/tmp/cat.jpg']]]);
});

it('enrolls, then posts a match for both sensors on ios', function () {
    $command = new RecordingCommand;

    inputIosDriver($command)->biometric('match');

    expect($command->calls)->toBe([
        ['xcrun', ['simctl', 'spawn', 'UDID', 'notifyutil', '-s', 'com.apple.BiometricKit.enrollmentChanged', '1']],
        ['xcrun', ['simctl', 'spawn', 'UDID', 'notifyutil', '-p', 'com.apple.BiometricKit.enrollmentChanged']],
        ['xcrun', ['simctl', 'spawn', 'UDID', 'notifyutil', '-p', 'com.apple.BiometricKit_Sim.fingerTouch.match']],
        ['xcrun', ['simctl', 'spawn', 'UDID', 'notifyutil', '-p', 'com.apple.BiometricKit_Sim.pearl.match']],
    ]);
});

it('posts no match for both sensors on ios', function () {
    $command = new RecordingCommand;

    inputIosDriver($command)->biometric('fail');

    expect(array_slice($command->calls, 2))->toBe([
        ['xcrun', ['simctl', 'spawn', 'UDID', 'notifyutil', '-p', 'com.apple.BiometricKit_Sim.fingerTouch.nomatch']],
        ['xcrun', ['simctl', 'spawn', 'UDID', 'notifyutil', '-p', 'com.apple.BiometricKit_Sim.pearl.nomatch']],
    ]);
});

it('only enrolls on ios when asked to enroll', function () {
    $command = new RecordingCommand;

    inputIosDriver($command)->biometric('enroll');

    expect($command->calls)->toHaveCount(2);
});

it('pushes a payload file to the ios app and removes it', function () {
    $command = new RecordingCommand;
    $payloads = [];
    $command->responder = function (string $binary, array $arguments) use (&$payloads): ?string {
        if (($arguments[1] ?? null) === 'push') {
            $payloads[] = json_decode((string) file_get_contents($arguments[4]), true);
        }

        return null;
    };

    inputIosDriver($command)->push(['aps' => ['alert' => ['title' => 'Hi']]]);

    expect($command->calls[0][1][0])->toBe('simctl')
        ->and(array_slice($command->calls[0][1], 0, 4))->toBe(['simctl', 'push', 'UDID', 'com.example.app'])
        ->and($payloads)->toBe([['aps' => ['alert' => ['title' => 'Hi']]]])
        ->and(is_file($command->calls[0][1][4]))->toBeFalse();
});

it('pushes media to the android pictures folder and scans it', function () {
    $command = new RecordingCommand;
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    inputAndroidDriver($command)->addMedia('/tmp/cat.jpg');

    expect($command->calls)->toHaveCount(2)
        ->and($command->calls[0][0])->toBe($adb)
        ->and(array_slice($command->calls[0][1], 0, 3))->toBe(['-s', 'emulator-5554', 'push'])
        ->and($command->calls[0][1][4])->toMatch('#^/sdcard/Pictures/pest-[0-9a-f]{8}-cat\.jpg$#')
        ->and($command->calls[1][1])->toBe([
            '-s', 'emulator-5554', 'shell', 'am', 'broadcast',
            '-a', 'android.intent.action.MEDIA_SCANNER_SCAN_FILE',
            '-d', 'file://'.$command->calls[0][1][4],
        ]);
});

it('touches the enrolled finger to match on android, and an unknown one to fail', function () {
    $command = new RecordingCommand;
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');
    $driver = inputAndroidDriver($command);

    $driver->biometric('enroll');
    $driver->biometric('match');
    $driver->biometric('fail');

    expect($command->calls)->toBe([
        [$adb, ['-s', 'emulator-5554', 'emu', 'finger', 'touch', '1']],
        [$adb, ['-s', 'emulator-5554', 'emu', 'finger', 'touch', '99']],
    ]);
});

it('refuses a push notification on android', function () {
    expect(fn () => inputAndroidDriver(new RecordingCommand)->push(['aps' => []]))
        ->toThrow(SimulatorException::class, 'push() only works on the iOS Simulator.');
});
