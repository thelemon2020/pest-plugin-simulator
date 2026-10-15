<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\AndroidSdk;
use NativePhp\Simulator\Arguments;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\EmulatorBoot;
use NativePhp\Simulator\Exceptions\SimulatorException;
use Tests\Support\RecordingCommand;

beforeEach(function () {
    $this->app = sys_get_temp_dir().'/simulator-boot-build-'.uniqid('', true);
    mkdir($this->app);
    Arguments::reset();
    Configuration::configure(['bundle_id' => 'com.example.app', 'app_directory' => $this->app]);
    resetBootBuilds();
});

afterEach(function () {
    Arguments::reset();
    Configuration::reset();
    resetBootBuilds();
    exec('rm -rf '.escapeshellarg($this->app));
});

function resetBootBuilds(): void
{
    (new ReflectionProperty(AndroidDriver::class, 'built'))->setValue(null, []);
    (new ReflectionProperty(AndroidDriver::class, 'wiped'))->setValue(null, []);
}

/**
 * adb before the emulator starts lists $before. Once it has started it lists $serial,
 * booted, and the boot has finished.
 */
function bootBuildCommand(string $serial, string $before = '', ?Closure $nativeRun = null): RecordingCommand
{
    $command = new RecordingCommand;
    $state = new ArrayObject(['started' => false]);
    $command->afterStart = function () use ($state): void {
        $state['started'] = true;
    };
    $command->responder = function (string $binary, array $arguments) use ($state, $serial, $before, $nativeRun): ?string {
        if ($arguments === ['devices']) {
            return "List of devices attached\n".($state['started'] ? "{$serial}\tdevice\n" : $before);
        }

        if (in_array('avd', $arguments, true) && in_array('name', $arguments, true)) {
            return "Pixel 8\nOK\n";
        }

        if (in_array('getprop', $arguments, true)) {
            $property = $arguments[array_search('getprop', $arguments, true) + 1] ?? '';

            return $property === 'init.svc.bootanim' ? "\n" : "1\n";
        }

        if (in_array('native:run', $arguments, true) && $nativeRun !== null) {
            return $nativeRun();
        }

        return null;
    };

    return $command;
}

function bootBuildDriver(RecordingCommand $command): AndroidDriver
{
    return new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);
}

function launchEmulator(AndroidDriver $driver, ?string $serial = null): void
{
    (new ReflectionMethod(AndroidDriver::class, 'launch'))->invoke($driver, '/tmp/app/emulator.log', EmulatorBoot::arguments('Pixel 8'), $serial);
}

/**
 * Where in the calls the first one with $token is.
 */
function callIndex(RecordingCommand $command, string $token): int
{
    foreach ($command->calls as $index => [, $arguments]) {
        if (in_array($token, $arguments, true)) {
            return $index;
        }
    }

    return -1;
}

it('builds the app while the emulator boots when it rebuilds anyway', function () {
    Arguments::intercept(['--rebuild']);
    // An emulator just shut down can still be listed, so the new one takes the next port.
    $command = bootBuildCommand('emulator-5556', "emulator-5554\toffline\n");
    $driver = bootBuildDriver($command);

    launchEmulator($driver);

    $start = $command->calls[callIndex($command, '-avd')][1];
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    expect(array_slice($start, -2))->toBe(['-port', '5556'])
        ->and($command->calls)->toContain(['php', ['artisan', 'native:run', 'android', 'emulator-5556', '--build=debug', '--no-tty']])
        ->and(callIndex($command, 'native:run'))->toBeLessThan(callIndex($command, 'sys.boot_completed'))
        ->and($command->calls)->toContain([$adb, ['-s', 'emulator-5556', 'shell', 'run-as', 'com.example.app', 'rm', '-rf', 'app_storage/laravel']])
        ->and(callIndex($command, 'install'))->toBe(-1)
        ->and($driver->freshlyInstalled())->toBeTrue();

    $calls = count($command->calls);
    (new ReflectionMethod(AndroidDriver::class, 'buildOnce'))->invoke($driver);

    expect($command->calls)->toHaveCount($calls);
});

it('installs the app after the boot when the build finished before the emulator was up', function () {
    Arguments::intercept(['--rebuild']);
    $apk = $this->app.'/nativephp/android/app/build/outputs/apk/debug/app-debug.apk';
    $command = bootBuildCommand('emulator-5554', '', function () use ($apk): string {
        mkdir(dirname($apk), 0777, true);
        file_put_contents($apk, 'apk');
        file_put_contents($this->app.'/nativephp/android-build.log', "Gradle build completed successfully\nInstalling APK\n");

        throw new SimulatorException('APK installation failed');
    });
    $driver = bootBuildDriver($command);

    launchEmulator($driver);

    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');
    $install = [$adb, ['-s', 'emulator-5554', 'install', '-r', '-d', $apk]];

    expect($command->calls)->toContain($install)
        ->and(callIndex($command, 'install'))->toBeGreaterThan(callIndex($command, 'sys.boot_completed'))
        ->and($command->timeouts[implode(' ', [$adb, ...$install[1]])])->toBe(300.0)
        ->and($driver->freshlyInstalled())->toBeTrue();
});

it('fails with the build when Gradle itself failed while the emulator booted', function () {
    Arguments::intercept(['--rebuild']);
    $command = bootBuildCommand('emulator-5554', '', function (): string {
        throw new SimulatorException('Gradle build failed');
    });

    expect(fn () => launchEmulator(bootBuildDriver($command)))
        ->toThrow(SimulatorException::class, 'Gradle build failed');

    expect(callIndex($command, 'sys.boot_completed'))->toBe(-1);
});

it('does not install an APK left by an earlier build', function () {
    Arguments::intercept(['--rebuild']);
    $apk = $this->app.'/nativephp/android/app/build/outputs/apk/debug/app-debug.apk';
    mkdir(dirname($apk), 0777, true);
    file_put_contents($apk, 'old apk');
    file_put_contents($this->app.'/nativephp/android-build.log', "Gradle build completed successfully\n");
    touch($apk, time() - 3600);
    touch($this->app.'/nativephp/android-build.log', time() - 3600);
    $command = bootBuildCommand('emulator-5554', '', function (): string {
        throw new SimulatorException('Could not open input file: artisan');
    });

    expect(fn () => launchEmulator(bootBuildDriver($command)))
        ->toThrow(SimulatorException::class, 'Could not open input file: artisan');

    expect(callIndex($command, 'install'))->toBe(-1);
});

it('builds on a worker emulator by the serial its port gives it', function () {
    Arguments::intercept(['--rebuild']);
    $command = bootBuildCommand('emulator-5600');
    $driver = bootBuildDriver($command);

    launchEmulator($driver, 'emulator-5600');

    expect($command->calls)->toContain(['php', ['artisan', 'native:run', 'android', 'emulator-5600', '--build=debug', '--no-tty']])
        ->and(callIndex($command, 'native:run'))->toBeLessThan(callIndex($command, 'sys.boot_completed'))
        ->and($command->calls[callIndex($command, '-avd')][1])->not->toContain('-port');
});

it('boots first when nothing says the app will be built', function () {
    $command = bootBuildCommand('emulator-5554');
    $driver = bootBuildDriver($command);

    launchEmulator($driver);

    expect(callIndex($command, 'native:run'))->toBe(-1)
        ->and($command->calls[callIndex($command, '-avd')][1])->not->toContain('-port')
        ->and($driver->freshlyInstalled())->toBeFalse();
});
