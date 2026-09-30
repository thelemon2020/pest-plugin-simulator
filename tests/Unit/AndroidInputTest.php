<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Exceptions\SimulatorException;
use Tests\Support\RecordingCommand;

afterEach(function () {
    Configuration::reset();
});

it('types punctuation and a newline through adb', function () {
    $command = new RecordingCommand;
    $driver = androidDriver($command);

    $driver->text("a+b:c'd");
    $driver->text("a b\nc");
    $driver->text('é');
    $driver->text('A@b');

    $typed = [];

    foreach ($command->calls as $call) {
        $arguments = $call[1];

        if (($arguments[3] ?? null) === 'input' && ($arguments[4] ?? null) === 'text') {
            $typed[] = $arguments[5];
        }

        if (($arguments[5] ?? null) === 'set-text') {
            $typed[] = $arguments[6];
        }

        if (($arguments[4] ?? null) === 'keyevent' && ($arguments[5] ?? null) === '279') {
            $typed[] = 'paste';
        }

        if (($arguments[4] ?? null) === 'keyevent' && ($arguments[5] ?? null) === '77') {
            $typed[] = '@';
        }
    }

    expect($typed)->toBe([
        "'a'",
        "'+'",
        "'b'",
        "':'",
        "'c'",
        "''\\'''",
        "'d'",
        "'a'",
        "'%s'",
        "'b'",
        "'\n'",
        'paste',
        "'c'",
        "'é'",
        'paste',
        "'A'",
        "'@'",
        'paste',
        "'b'",
    ]);
});

it('taps the at key when the keyboard shows it', function () {
    $command = new RecordingCommand;
    $command->outputs['uiautomator'] = '<hierarchy rotation="0" width="1080" height="2400"><node text="@" class="android.widget.TextView" bounds="[100,1600][200,1700]" /></hierarchy>';
    $driver = androidDriver($command);

    $driver->text('A@b');

    $taps = array_values(array_filter(
        $command->calls,
        fn (array $call): bool => ($call[1][4] ?? null) === 'tap',
    ));

    expect($taps[0][1])->toBe(['-s', 'emulator-5554', 'shell', 'input', 'tap', '150', '1650']);
});

it('clears by deleting from the end of the field', function () {
    $command = new RecordingCommand;
    $driver = androidDriver($command);

    $driver->clear();
    $driver->clear(50);
    $driver->back();

    expect($command->calls[0][1])->toBe([
        '-s', 'emulator-5554', 'shell', 'input', 'keyevent', '123',
        ...array_fill(0, 40, '67'),
    ])->and($command->calls[1][1])->toBe([
        '-s', 'emulator-5554', 'shell', 'input', 'keyevent', '123',
        ...array_fill(0, 40, '67'),
    ])->and($command->calls[2][1])->toBe([
        '-s', 'emulator-5554', 'shell', 'input', 'keyevent', '123',
        ...array_fill(0, 10, '67'),
    ])->and($command->calls[3][1])->toBe(['-s', 'emulator-5554', 'shell', 'input', 'keyevent', '4']);
});

it('reads the emulator screen size from the hierarchy', function () {
    $command = new RecordingCommand;
    $command->output = '<hierarchy rotation="0" width="1080" height="2400"><node text="Home" class="android.widget.TextView" bounds="[0,0][200,80]" selected="true" enabled="true" /></hierarchy>';
    $driver = androidDriver($command);

    $elements = $driver->describe();

    expect($driver->viewport())->toBe([1080.0, 2400.0])
        ->and($elements[0]['label'])->toBe('Home')
        ->and($elements[0]['selected'])->toBeTrue();
});

it('reads a stock hierarchy from node bounds', function () {
    $command = new RecordingCommand;
    $command->output = <<<'XML'
        <hierarchy rotation="0">
            <node class="android.widget.FrameLayout" bounds="[0,0][1080,2400]">
                <node class="androidx.appcompat.widget.Toolbar" bounds="[0,0][1080,168]">
                    <node text="Notes" class="android.widget.TextView" bounds="[48,72][240,132]" />
                </node>
            </node>
        </hierarchy>
        XML;
    $driver = androidDriver($command);
    $path = tempnam(sys_get_temp_dir(), 'tree');

    try {
        $elements = $driver->describe($path);
        $saved = json_decode((string) file_get_contents((string) $path), true);

        expect($driver->viewport())->toBe([1080.0, 2400.0])
            ->and($elements[0]['label'])->toBe('Notes')
            ->and($elements[0]['chrome'])->toBe('navigation')
            ->and($saved[2]['text'])->toBe('Notes')
            ->and(isset($saved[2]['__inherited']))->toBeFalse();
    } finally {
        if (is_string($path)) {
            @unlink($path);
        }
    }
});

it('retries a native build that nativephp timed out', function () {
    (new ReflectionProperty(AndroidDriver::class, 'built'))->setValue(null, []);
    $command = new RecordingCommand;
    $attempts = 0;
    $command->responder = function (string $binary, array $arguments) use (&$attempts): ?string {
        if ($binary !== 'php' || ! in_array('native:run', $arguments, true)) {
            return null;
        }

        $attempts++;

        if ($attempts === 1) {
            throw new SimulatorException('The process "./gradlew assembleDebug" exceeded the timeout of 600 seconds.');
        }

        return '';
    };
    $driver = androidDriver($command);
    $build = new ReflectionMethod(AndroidDriver::class, 'buildOnce');

    $build->invoke($driver);
    $build->invoke($driver);

    expect($attempts)->toBe(2);
});

it('does not retry a native build that failed to compile', function () {
    (new ReflectionProperty(AndroidDriver::class, 'built'))->setValue(null, []);
    $command = new RecordingCommand;
    $command->failures = ['native:run' => 'Gradle build failed'];
    $driver = androidDriver($command);

    expect(fn () => (new ReflectionMethod(AndroidDriver::class, 'buildOnce'))->invoke($driver))
        ->toThrow(SimulatorException::class, 'Gradle build failed');

    $builds = array_values(array_filter(
        $command->calls,
        fn (array $call): bool => $call[0] === 'php' && in_array('native:run', $call[1], true),
    ));

    expect($builds)->toHaveCount(1);
});

function androidDriver(RecordingCommand $command): AndroidDriver
{
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    return $driver;
}
