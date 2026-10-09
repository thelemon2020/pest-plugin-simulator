<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\AndroidSdk;
use NativePhp\Simulator\Arguments;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\DeviceWipe;
use NativePhp\Simulator\EmulatorBoot;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\IosDriver;
use NativePhp\Simulator\Shutdown;
use NativePhp\Simulator\SnapshotWriter;
use NativePhp\Simulator\Worker;
use Tests\Support\RecordingCommand;
use Tests\Support\ScriptedSocket;

afterEach(function () {
    Configuration::reset();
    Arguments::reset();
    resetDriverState();
});

it('gives each worker its own idb_companion port and emulator port', function () {
    withWorker(0, function (): void {
        expect(Worker::parallel())->toBeTrue()
            ->and(Worker::grpcPort())->toBe(10883)
            ->and(Worker::emulatorPort())->toBe(5556);
    });

    withWorker(1, function (): void {
        expect(Worker::grpcPort())->toBe(10884)
            ->and(Worker::emulatorPort())->toBe(5558);
    });

    withWorker(2, function (): void {
        expect(Worker::grpcPort())->toBe(10885)
            ->and(Worker::emulatorPort())->toBe(5560);
    });
});

it('boots a private simulator and companion for a parallel worker', function () {
    withWorker(1, function (): void {
        $socket = new ScriptedSocket;
        $command = new RecordingCommand;
        $command->afterStart = function () use ($socket): void {
            $socket->listening = true;
        };
        $command->outputs = [
            '-j' => simulatorJson(),
            'clone' => "CLONE\n",
            'get_app_container' => "/tmp/NativePHP.app\n",
            '--entitlements' => '<key>get-task-allow</key><true/>',
        ];
        $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command, $socket);

        $driver->ensureReady();
        $driver->ensureReady();

        expect($socket->ports)->not->toBeEmpty()->each->toBe(10884)
            ->and(cloneCalls($command))->toBe([
                ['xcrun', ['simctl', 'clone', 'SOURCE', 'iPhone 17 pest-1']],
            ])
            ->and($command->calls)->toContain(['xcrun', ['simctl', 'boot', 'CLONE']])
            ->and($command->calls)->toContain(['/bin/echo', ['--udid', 'CLONE', '--grpc-port', '10884', '--log-level', 'info']])
            ->and(shutdownCalls($command))->toBe([]);

        Shutdown::run();

        expect($command->calls)->toContain(['xcrun', ['simctl', 'shutdown', 'CLONE']])
            ->and($command->calls)->not->toContain(['xcrun', ['simctl', 'delete', 'CLONE']]);
    }, '1_worker');
});

it('boots an existing worker simulator instead of cloning another', function () {
    withWorker(1, function (): void {
        [$command, $driver] = iosWorker(simulatorJson([
            ['name' => 'iPhone 17 pest-1', 'udid' => 'CLONE', 'state' => 'Shutdown', 'isAvailable' => true],
        ]));

        $driver->ensureReady();

        expect(cloneCalls($command))->toBe([])
            ->and($command->calls)->toContain(['xcrun', ['simctl', 'boot', 'CLONE']])
            ->and($command->calls)->toContain(['xcrun', ['simctl', 'bootstatus', 'CLONE', '-b']]);
    });
});

it('leaves a booted worker simulator up when the hardware keyboard is already connected', function () {
    withWorker(1, function (): void {
        [$command, $driver] = iosWorker(simulatorJson([
            ['name' => 'iPhone 17 pest-1', 'udid' => 'CLONE', 'state' => 'Booted', 'isAvailable' => true],
        ]));
        $command->outputs['ConnectHardwareKeyboard'] = "1\n";

        $driver->ensureReady();

        $booted = array_values(array_filter(
            $command->calls,
            fn (array $call): bool => in_array('boot', $call[1], true) || in_array('clone', $call[1], true) || in_array('shutdown', $call[1], true),
        ));

        expect($booted)->toBe([])
            ->and($command->calls)->toContain(['xcrun', ['simctl', 'bootstatus', 'CLONE', '-b']])
            ->and($command->calls)->toContain(['defaults', ['read', 'com.apple.iphonesimulator', 'ConnectHardwareKeyboard']]);
    });
});

it('replaces a worker simulator cloned from an older runtime', function () {
    withWorker(1, function (): void {
        [$command, $driver] = iosWorker((string) json_encode([
            'devices' => [
                'com.apple.CoreSimulator.SimRuntime.iOS-18-0' => [
                    ['name' => 'iPhone 17 pest-1', 'udid' => 'OLD', 'state' => 'Shutdown', 'isAvailable' => true],
                ],
                'com.apple.CoreSimulator.SimRuntime.iOS-26-0' => [
                    ['name' => 'iPhone 17', 'udid' => 'SOURCE', 'state' => 'Shutdown', 'isAvailable' => true],
                ],
            ],
        ]));
        $command->outputs['clone'] = "FRESH\n";

        $driver->ensureReady();

        expect($command->calls)->toContain(['xcrun', ['simctl', 'delete', 'OLD']])
            ->and(cloneCalls($command))->toBe([
                ['xcrun', ['simctl', 'clone', 'SOURCE', 'iPhone 17 pest-1']],
            ])
            ->and($command->calls)->toContain(['xcrun', ['simctl', 'boot', 'FRESH']])
            ->and($command->calls)->toContain(['xcrun', ['simctl', 'bootstatus', 'FRESH', '-b']]);
    });
});

it('restarts the companion when the worker changes device', function () {
    withWorker(1, function (): void {
        $socket = new ScriptedSocket;
        $command = recordingClones($socket, simulatorJson());
        $configuration = Configuration::resolve();

        (new IosDriver(new Device('ios', 'iPhone 17', true), $configuration, $command, $socket))->ensureReady();
        (new IosDriver(new Device('ios', 'iPad Air', true), $configuration, $command, $socket))->ensureReady();

        $starts = array_values(array_filter(
            $command->calls,
            fn (array $call): bool => ($call[1][0] ?? null) === '--udid',
        ));
        $killAt = array_key_first(array_filter($command->calls, fn (array $call): bool => $call[0] === 'kill'));
        $secondStartAt = array_key_last(array_filter(
            $command->calls,
            fn (array $call): bool => in_array('CLONE-2', $call[1], true),
        ));

        $releasedAt = array_key_first(array_filter(
            $command->calls,
            fn (array $call): bool => ($call[1][1] ?? null) === 'shutdown' && ($call[1][2] ?? null) === 'CLONE-1',
        ));
        $secondBootAt = array_key_first(array_filter(
            $command->calls,
            fn (array $call): bool => ($call[1][1] ?? null) === 'boot' && ($call[1][2] ?? null) === 'CLONE-2',
        ));

        expect($starts)->toHaveCount(2)
            ->and($starts[0][1])->toBe(['--udid', 'CLONE-1', '--grpc-port', '10884', '--log-level', 'info'])
            ->and($starts[1][1])->toBe(['--udid', 'CLONE-2', '--grpc-port', '10884', '--log-level', 'info'])
            ->and($killAt)->toBeLessThan($secondStartAt)
            ->and($releasedAt)->toBeLessThan($secondBootAt);
    });
});

it('boots the emulator in software without a window or a data wipe', function () {
    withWorker(null, function (): void {
        $command = bootRecorder();
        $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);

        $driver->ensureReady();

        expect(emulatorArguments($command))->toBe(EmulatorBoot::arguments('Pixel 8'))
            ->and(EmulatorBoot::arguments('Pixel 8'))->not->toContain('-wipe-data')
            ->and(EmulatorBoot::arguments('Pixel 8'))->not->toContain('-no-snapshot-load')
            ->and(EmulatorBoot::arguments('Pixel 8'))->toContain('-no-boot-anim')
            ->and(EmulatorBoot::arguments('Pixel 8', 5558))->toContain('-no-snapshot-save')
            ->and(EmulatorBoot::arguments('Pixel 8', 5558))->toContain('-read-only')
            ->and(EmulatorBoot::arguments('Pixel 8', 5558, true))->not->toContain('-read-only')
            ->and(EmulatorBoot::arguments('Pixel 8', 5558, true))->not->toContain('-no-snapshot-save')
            ->and(EmulatorBoot::arguments('Pixel 8', 5558, true))->toContain('5558')
            ->and(EmulatorBoot::arguments('Pixel 8', 5558))->not->toContain('-wipe-data')
            ->and(EmulatorBoot::TIMEOUT_SECONDS)->toBeGreaterThanOrEqual(120);
    });
});

it('wipes a booted emulator when asked', function () {
    Arguments::intercept(['--wipe']);

    withWorker(null, function (): void {
        $command = bootRecorder(alreadyRunning: true);
        $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);
        $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

        $driver->ensureReady();

        expect(emulatorArguments($command))->toBe(DeviceWipe::emulator(EmulatorBoot::arguments('Pixel 8')))
            ->and($command->calls)->toContain([$adb, ['-s', 'emulator-5558', 'emu', 'kill']]);
    });
});

it('erases a booted simulator when asked to wipe', function () {
    Arguments::intercept(['--wipe']);

    withWorker(null, function (): void {
        $socket = new ScriptedSocket;
        $command = new RecordingCommand;
        $command->afterStart = function () use ($socket): void {
            $socket->listening = true;
        };
        $command->outputs = [
            '-j' => simulatorJson(),
            'get_app_container' => "/tmp/NativePHP.app\n",
            '--entitlements' => '<key>get-task-allow</key><true/>',
        ];
        $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command, $socket);

        $driver->ensureReady();
        $driver->ensureReady();

        expect($command->calls)->toContain(['xcrun', ['simctl', 'shutdown', 'SOURCE']])
            ->and($command->calls)->toContain(['xcrun', ['simctl', 'erase', 'SOURCE']])
            ->and($command->calls)->toContain(['xcrun', ['simctl', 'boot', 'SOURCE']])
            ->and(array_values(array_filter(
                $command->calls,
                fn (array $call): bool => in_array('erase', $call[1], true),
            )))->toHaveCount(1);
    });
});

it('deletes a worker simulator when asked to wipe', function () {
    Arguments::intercept(['--wipe']);

    withWorker(1, function (): void {
        $socket = new ScriptedSocket;
        $command = new RecordingCommand;
        $command->afterStart = function () use ($socket): void {
            $socket->listening = true;
        };
        $command->outputs = [
            '-j' => (string) json_encode([
                'devices' => [
                    'com.apple.CoreSimulator.SimRuntime.iOS-26-0' => [
                        ['name' => 'iPhone 17', 'udid' => 'SOURCE', 'state' => 'Shutdown', 'isAvailable' => true],
                        ['name' => 'iPhone 17 pest-1', 'udid' => 'CLONE', 'state' => 'Shutdown', 'isAvailable' => true],
                    ],
                ],
            ]),
            'clone' => "FRESH\n",
            'get_app_container' => "/tmp/NativePHP.app\n",
            '--entitlements' => '<key>get-task-allow</key><true/>',
        ];
        $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command, $socket);

        $driver->ensureReady();
        $driver->ensureReady();

        expect($command->calls)->toContain(['xcrun', ['simctl', 'delete', 'CLONE']])
            ->and(cloneCalls($command))->toBe([
                ['xcrun', ['simctl', 'clone', 'SOURCE', 'iPhone 17 pest-1']],
            ])
            ->and($command->calls)->toContain(['xcrun', ['simctl', 'boot', 'FRESH']]);
    });
});

it('gives a parallel worker its own emulator port', function () {
    withWorker(1, function (): void {
        $command = bootRecorder(alreadyRunning: true);
        $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);

        $driver->ensureReady();

        expect(emulatorArguments($command))->toBe(EmulatorBoot::arguments('Pixel 8', 5558, true))
            ->and((new ReflectionProperty(AndroidDriver::class, 'serial'))->getValue($driver))->toBe('emulator-5558')
            ->and(array_merge(...array_column($command->calls, 1)))->not->toContain('kill');
    });
});

it('loads the quick boot snapshot read-only when another worker is saving it', function () {
    $handle = fopen(SnapshotWriter::path('Pixel 8'), 'c');
    expect($handle)->not->toBeFalse();
    flock($handle, LOCK_EX);

    try {
        withWorker(1, function (): void {
            $command = bootRecorder(alreadyRunning: true);
            $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);

            $driver->ensureReady();

            expect(emulatorArguments($command))->toBe(EmulatorBoot::arguments('Pixel 8', 5558));
        });
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});

it('waits for the emulator to finish saving its snapshot', function () {
    withWorker(null, function (): void {
        $command = bootRecorder();
        $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);
        $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

        $driver->ensureReady();
        Shutdown::run();

        expect($command->calls)->toContain([$adb, ['-s', 'emulator-5554', 'emu', 'kill']])
            ->and($command->calls)->not->toContain(['kill', ['4242']]);
    });
});

it('stops the emulator when the snapshot save does not exit', function () {
    withWorker(null, function (): void {
        $command = bootRecorder();
        $command->exited = false;
        $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);

        $driver->ensureReady();
        Shutdown::run();

        $savedAt = array_key_first(array_filter(
            $command->calls,
            fn (array $call): bool => ($call[1][2] ?? null) === 'emu' && ($call[1][3] ?? null) === 'kill',
        ));
        $stoppedAt = array_key_first(array_filter(
            $command->calls,
            fn (array $call): bool => $call === ['kill', ['4242']],
        ));

        expect($savedAt)->toBeInt()
            ->and($stoppedAt)->toBeInt()
            ->and($savedAt)->toBeLessThan($stoppedAt);
    });
});

it('keeps waiting when the emulator console is not ready', function () {
    $command = new RecordingCommand;
    $names = 0;
    $command->responder = function (string $binary, array $arguments) use (&$names): ?string {
        if (in_array('devices', $arguments, true)) {
            return "List of devices attached\nemulator-5554\tdevice\n";
        }

        if (in_array('name', $arguments, true)) {
            $names++;

            if ($names === 1) {
                throw new SimulatorException('device offline');
            }

            return "Pixel 8\nOK\n";
        }

        if (in_array('getprop', $arguments, true)) {
            $property = $arguments[array_search('getprop', $arguments, true) + 1] ?? '';

            return $property === 'init.svc.bootanim' ? "stopped\n" : "1\n";
        }

        if (in_array('path', $arguments, true)) {
            return "package:/system/framework/framework-res.apk\n";
        }

        return null;
    };
    $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);

    (new ReflectionMethod(AndroidDriver::class, 'waitUntilBooted'))->invoke($driver, '/tmp/app/emulator.log', null, 5);

    expect($names)->toBe(2)
        ->and((new ReflectionProperty(AndroidDriver::class, 'serial'))->getValue($driver))->toBe('emulator-5554');
});

it('starts its own companion when the listener belongs to another simulator', function () {
    withWorker(null, function (): void {
        $socket = new ScriptedSocket;
        $socket->portsListening = [10882 => true];
        $command = new RecordingCommand;
        $command->afterStart = function () use ($socket): void {
            $socket->portsListening[10883] = true;
        };
        $command->responder = function (string $binary, array $arguments): ?string {
            if (in_array('-j', $arguments, true)) {
                return simulatorJson();
            }

            if ($binary === 'lsof') {
                return "999\n";
            }

            if ($binary === 'ps') {
                return "/opt/homebrew/bin/idb_companion --udid OTHER --grpc-port 10882\n";
            }

            if (in_array('get_app_container', $arguments, true)) {
                return "/tmp/NativePHP.app\n";
            }

            if (in_array('--entitlements', $arguments, true)) {
                return '<key>get-task-allow</key><true/>';
            }

            return null;
        };
        $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command, $socket);

        $driver->ensureReady();

        expect($command->calls)->toContain(['/bin/echo', ['--udid', 'SOURCE', '--grpc-port', '10883', '--log-level', 'info']])
            ->and($command->calls)->not->toContain(['kill', ['999']]);
    });
});

it('names the emulator log when a cold boot does not finish', function () {
    $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), new RecordingCommand);

    expect(fn () => (new ReflectionMethod(AndroidDriver::class, 'waitUntilBooted'))->invoke($driver, '/tmp/app/emulator.log', null, 0))
        ->toThrow(SimulatorException::class, 'The Android Emulator [Pixel 8] did not boot. See /tmp/app/emulator.log.');
});

it('stops waiting when the emulator process has already exited', function () {
    $log = tempnam(sys_get_temp_dir(), 'emulator');
    file_put_contents($log, "FATAL        | Not enough space to create userdata partition. Available: 7355 MB, need 12288 MB.\n");
    $command = new RecordingCommand;
    $command->alive = false;
    $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'emulatorPid'))->setValue($driver, 4242);
    $started = microtime(true);

    try {
        expect(fn () => (new ReflectionMethod(AndroidDriver::class, 'waitUntilBooted'))->invoke($driver, $log, null, 30))
            ->toThrow(SimulatorException::class, 'Not enough space to create userdata partition');
        expect(microtime(true) - $started)->toBeLessThan(2);
    } finally {
        if (is_string($log)) {
            @unlink($log);
        }
    }
});

it('lists the shared simulators once, not on every screen', function () {
    withWorker(null, function (): void {
        [$command, $driver] = iosWorker(simulatorJson());

        $driver->ensureReady();
        $driver->ensureReady();
        $driver->ensureReady();

        expect(simulatorListings($command))->toBe(1);
    });
});

it('lists the shared simulators again once another device has booted in between', function () {
    withWorker(null, function (): void {
        $socket = new ScriptedSocket;
        $command = recordingClones($socket, simulatorJson());
        $configuration = Configuration::resolve();
        $phone = new IosDriver(new Device('ios', 'iPhone 17', true), $configuration, $command, $socket);
        $pad = new IosDriver(new Device('ios', 'iPad Air', true), $configuration, $command, $socket);

        $phone->ensureReady();
        $pad->ensureReady();
        $phone->ensureReady();
        $phone->ensureReady();

        expect(simulatorListings($command))->toBe(3);
    });
});

it('lists the shared emulators once, not on every screen', function () {
    withWorker(null, function (): void {
        $command = bootRecorder(alreadyRunning: true);
        $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);

        $driver->ensureReady();
        $driver->ensureReady();
        $driver->ensureReady();

        expect(emulatorListings($command))->toBe(1);
    });
});

it('lists the shared emulators again once another device has booted in between', function () {
    withWorker(null, function (): void {
        $command = new RecordingCommand;
        $command->responder = function (string $binary, array $arguments): ?string {
            if ($arguments === ['devices']) {
                return "List of devices attached\nemulator-5554\tdevice\nemulator-5556\tdevice\n";
            }

            if (in_array('name', $arguments, true)) {
                return $arguments[1] === 'emulator-5554' ? "Pixel 8\nOK\n" : "Pixel Tablet\nOK\n";
            }

            return null;
        };
        $configuration = Configuration::resolve();
        $pixel = new AndroidDriver(new Device('android', 'Pixel 8', true), $configuration, $command);
        $tablet = new AndroidDriver(new Device('android', 'Pixel Tablet', true), $configuration, $command);

        $pixel->ensureReady();
        $tablet->ensureReady();
        $pixel->ensureReady();
        $pixel->ensureReady();

        expect(emulatorListings($command))->toBe(3);
    });
});

function withWorker(?int $index, Closure $test, ?string $unique = null): void
{
    $token = getenv('TEST_TOKEN');
    $label = getenv('UNIQUE_TEST_TOKEN');
    $companion = getenv('IDB_COMPANION');

    if ($index === null) {
        putenv('TEST_TOKEN');
    } else {
        putenv('TEST_TOKEN='.$index);
    }

    if ($unique === null) {
        putenv('UNIQUE_TEST_TOKEN');
    } else {
        putenv('UNIQUE_TEST_TOKEN='.$unique);
    }

    putenv('IDB_COMPANION=/bin/echo');

    try {
        $test();
    } finally {
        putenv($token === false ? 'TEST_TOKEN' : 'TEST_TOKEN='.$token);
        putenv($label === false ? 'UNIQUE_TEST_TOKEN' : 'UNIQUE_TEST_TOKEN='.$label);
        putenv($companion === false ? 'IDB_COMPANION' : 'IDB_COMPANION='.$companion);
        resetDriverState();
    }
}

function resetDriverState(): void
{
    (new ReflectionProperty(IosDriver::class, 'built'))->setValue(null, []);
    (new ReflectionProperty(IosDriver::class, 'companionPid'))->setValue(null, null);
    (new ReflectionProperty(IosDriver::class, 'companionSimulator'))->setValue(null, null);
    (new ReflectionProperty(IosDriver::class, 'workerSimulator'))->setValue(null, null);
    (new ReflectionProperty(AndroidDriver::class, 'built'))->setValue(null, []);
    (new ReflectionProperty(AndroidDriver::class, 'privateSerial'))->setValue(null, null);
    (new ReflectionProperty(AndroidDriver::class, 'privateDevice'))->setValue(null, null);
    (new ReflectionProperty(IosDriver::class, 'hardwareKeyboard'))->setValue(null, false);
    (new ReflectionProperty(IosDriver::class, 'wiped'))->setValue(null, []);
    (new ReflectionProperty(AndroidDriver::class, 'wiped'))->setValue(null, []);
    (new ReflectionProperty(IosDriver::class, 'ready'))->setValue(null, null);
    (new ReflectionProperty(AndroidDriver::class, 'ready'))->setValue(null, null);
    SnapshotWriter::reset();
    Shutdown::reset();
}

/**
 * @param  list<array{name: string, udid: string, state: string, isAvailable: bool}>  $also
 */
function simulatorJson(array $also = []): string
{
    return (string) json_encode([
        'devices' => [
            'com.apple.CoreSimulator.SimRuntime.iOS-26-0' => [
                ['name' => 'iPhone 17', 'udid' => 'SOURCE', 'state' => 'Booted', 'isAvailable' => true],
                ['name' => 'iPad Air', 'udid' => 'PAD', 'state' => 'Booted', 'isAvailable' => true],
                ...$also,
            ],
        ],
    ]);
}

/**
 * @return array{0: RecordingCommand, 1: IosDriver}
 */
function iosWorker(string $json): array
{
    $socket = new ScriptedSocket;
    $command = new RecordingCommand;
    $command->afterStart = function () use ($socket): void {
        $socket->listening = true;
    };
    $command->outputs = [
        '-j' => $json,
        'get_app_container' => "/tmp/NativePHP.app\n",
        '--entitlements' => '<key>get-task-allow</key><true/>',
    ];

    return [$command, new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command, $socket)];
}

/**
 * @return list<array{0: string, 1: list<string>}>
 */
function cloneCalls(RecordingCommand $command): array
{
    return array_values(array_filter(
        $command->calls,
        fn (array $call): bool => in_array('clone', $call[1], true),
    ));
}

/**
 * @return list<array{0: string, 1: list<string>}>
 */
function shutdownCalls(RecordingCommand $command): array
{
    return array_values(array_filter(
        $command->calls,
        fn (array $call): bool => in_array('shutdown', $call[1], true) || in_array('delete', $call[1], true),
    ));
}

/**
 * @return list<string>
 */
function emulatorArguments(RecordingCommand $command): array
{
    foreach ($command->calls as $call) {
        if (($call[1][0] ?? null) === '-avd') {
            return $call[1];
        }
    }

    return [];
}

function recordingClones(ScriptedSocket $socket, string $json): RecordingCommand
{
    $command = new RecordingCommand;
    $clones = 0;
    $command->afterStart = function () use ($socket): void {
        $socket->listening = true;
    };
    $command->responder = function (string $binary, array $arguments) use (&$clones, $json): ?string {
        if (in_array('clone', $arguments, true)) {
            $clones++;

            return 'CLONE-'.$clones;
        }

        if (in_array('-j', $arguments, true)) {
            return $json;
        }

        if (in_array('get_app_container', $arguments, true)) {
            return "/tmp/NativePHP.app\n";
        }

        if (in_array('--entitlements', $arguments, true)) {
            return '<key>get-task-allow</key><true/>';
        }

        return null;
    };

    return $command;
}

function bootRecorder(bool $alreadyRunning = false): RecordingCommand
{
    $command = new RecordingCommand;
    $lists = 0;
    $command->responder = function (string $binary, array $arguments) use (&$lists, $alreadyRunning): ?string {
        if (in_array('devices', $arguments, true)) {
            $lists++;

            if ($alreadyRunning) {
                return "List of devices attached\nemulator-5554\tdevice\nemulator-5558\tdevice\n";
            }

            if ($lists > 1) {
                return "List of devices attached\nemulator-5554\tdevice\n";
            }

            return "List of devices attached\n";
        }

        if (in_array('name', $arguments, true)) {
            return "Pixel 8\nOK\n";
        }

        if (in_array('getprop', $arguments, true)) {
            $property = $arguments[array_search('getprop', $arguments, true) + 1] ?? '';

            return $property === 'init.svc.bootanim' ? "stopped\n" : "1\n";
        }

        return null;
    };

    return $command;
}

function simulatorListings(RecordingCommand $command): int
{
    return count(array_filter(
        $command->calls,
        fn (array $call): bool => $call === ['xcrun', ['simctl', 'list', 'devices', 'available', '-j']],
    ));
}

function emulatorListings(RecordingCommand $command): int
{
    return count(array_filter(
        $command->calls,
        fn (array $call): bool => $call[1] === ['devices'],
    ));
}
