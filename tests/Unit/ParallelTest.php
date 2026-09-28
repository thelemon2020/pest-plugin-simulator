<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\EmulatorBoot;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\IosDriver;
use NativePhp\Simulator\Shutdown;
use NativePhp\Simulator\Worker;
use Tests\Support\RecordingCommand;
use Tests\Support\ScriptedSocket;

afterEach(function () {
    Configuration::reset();
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
                ['xcrun', ['simctl', 'clone', 'SOURCE', 'iPhone 17 1_worker']],
            ])
            ->and($command->calls)->toContain(['xcrun', ['simctl', 'boot', 'CLONE']])
            ->and($command->calls)->toContain(['/bin/echo', ['--udid', 'CLONE', '--grpc-port', '10884', '--log-level', 'info']])
            ->and(shutdownCalls($command))->toBe([]);

        Shutdown::run();

        expect($command->calls)->toContain(['xcrun', ['simctl', 'shutdown', 'CLONE']])
            ->and($command->calls)->toContain(['xcrun', ['simctl', 'delete', 'CLONE']]);
    }, '1_worker');
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

it('cold boots the emulator in software without a window', function () {
    withWorker(null, function (): void {
        $command = bootRecorder();
        $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);

        $driver->ensureReady();

        expect(emulatorArguments($command))->toBe(EmulatorBoot::arguments('Pixel 8'))
            ->and(EmulatorBoot::TIMEOUT_SECONDS)->toBeGreaterThanOrEqual(120);
    });
});

it('gives a parallel worker its own emulator port', function () {
    withWorker(1, function (): void {
        $command = bootRecorder(alreadyRunning: true);
        $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);

        $driver->ensureReady();

        expect(emulatorArguments($command))->toBe(EmulatorBoot::arguments('Pixel 8', 5558))
            ->and((new ReflectionProperty(AndroidDriver::class, 'serial'))->getValue($driver))->toBe('emulator-5558')
            ->and(array_merge(...array_column($command->calls, 1)))->not->toContain('kill');
    });
});

it('names the emulator log when a cold boot does not finish', function () {
    $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), new RecordingCommand);

    expect(fn () => (new ReflectionMethod(AndroidDriver::class, 'waitUntilBooted'))->invoke($driver, '/tmp/app/emulator.log', null, 0))
        ->toThrow(SimulatorException::class, 'The Android Emulator [Pixel 8] did not boot. See /tmp/app/emulator.log.');
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
    Shutdown::reset();
}

function simulatorJson(): string
{
    return (string) json_encode([
        'devices' => [
            'com.apple.CoreSimulator.SimRuntime.iOS-26-0' => [
                ['name' => 'iPhone 17', 'udid' => 'SOURCE', 'state' => 'Booted', 'isAvailable' => true],
                ['name' => 'iPad Air', 'udid' => 'PAD', 'state' => 'Booted', 'isAvailable' => true],
            ],
        ],
    ]);
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
            return "1\n";
        }

        return null;
    };

    return $command;
}
