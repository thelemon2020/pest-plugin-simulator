<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\Command;
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
    $textCalls = 0;

    foreach ($command->calls as $call) {
        $arguments = $call[1];
        $script = $arguments[3] ?? null;

        if (($arguments[2] ?? null) === 'shell' && is_string($script) && str_contains($script, 'input text')) {
            $textCalls++;
            preg_match_all('/input text (.*?)(?: && |; |$)/', $script, $matches);

            foreach ($matches[1] as $token) {
                $typed[] = $token;
            }
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
    ])->and($textCalls)->toBe(5);

    $scripts = [];

    foreach ($command->calls as $call) {
        $script = $call[1][3] ?? null;

        if (($call[1][2] ?? null) === 'shell' && is_string($script) && str_contains($script, 'input text')) {
            $scripts[] = $script;
        }
    }

    expect(implode("\n", $scripts))->toContain('sleep 0.1')
        ->and(implode("\n", $scripts))->not->toContain('&&');
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

it('swipes for a given duration and holds a press', function () {
    $command = new RecordingCommand;
    $driver = androidDriver($command);

    $driver->swipe(10, 20, 30, 40, 0.15);
    $driver->press(50, 60, 0.8);

    expect($command->calls[0][1])->toBe([
        '-s', 'emulator-5554', 'shell', 'input', 'swipe', '10', '20', '30', '40', '150',
    ])->and($command->calls[1][1])->toBe([
        '-s', 'emulator-5554', 'shell', 'input', 'swipe', '50', '60', '50', '60', '800',
    ]);
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

it('waits out an app that is not responding by the button id, in any language', function () {
    $command = new RecordingCommand;
    $dialog = <<<'XML'
        <hierarchy rotation="0" width="1080" height="2400">
            <node text="Warten" resource-id="android:id/aerr_wait" class="android.widget.Button" package="android" bounds="[100,1300][980,1420]" />
            <node text="App schließen" resource-id="android:id/aerr_close" class="android.widget.Button" package="android" bounds="[100,1180][980,1300]" />
        </hierarchy>
        XML;
    $app = '<hierarchy rotation="0" width="1080" height="2400"><node text="Home" class="android.widget.TextView" bounds="[0,0][200,80]" /></hierarchy>';
    $dumps = [$dialog, $app];
    $command->responder = function (string $binary, array $arguments) use (&$dumps): ?string {
        return in_array('uiautomator', $arguments, true) ? array_shift($dumps) : null;
    };
    $driver = androidDriver($command);

    $elements = $driver->describe();

    $taps = array_values(array_filter($command->calls, fn (array $call): bool => ($call[1][4] ?? null) === 'tap'));

    expect($taps)->toHaveCount(1)
        ->and($taps[0][1])->toBe(['-s', 'emulator-5554', 'shell', 'input', 'tap', '540', '1360'])
        ->and(array_column($elements, 'label'))->toBe(['Home']);
});

it('waits out an app that is not responding by an English label when the id is missing', function () {
    $command = new RecordingCommand;
    $dialog = <<<'XML'
        <hierarchy rotation="0" width="1080" height="2400">
            <node text="Close app" class="android.widget.Button" bounds="[100,1180][980,1300]" />
            <node text="Wait" class="android.widget.Button" bounds="[100,1300][980,1420]" />
        </hierarchy>
        XML;
    $app = '<hierarchy rotation="0" width="1080" height="2400"><node text="Home" class="android.widget.TextView" bounds="[0,0][200,80]" /></hierarchy>';
    $dumps = [$dialog, $app];
    $command->responder = function (string $binary, array $arguments) use (&$dumps): ?string {
        return in_array('uiautomator', $arguments, true) ? array_shift($dumps) : null;
    };
    $driver = androidDriver($command);

    $driver->describe();

    $taps = array_values(array_filter($command->calls, fn (array $call): bool => ($call[1][4] ?? null) === 'tap'));

    expect($taps)->toHaveCount(1)
        ->and($taps[0][1])->toBe(['-s', 'emulator-5554', 'shell', 'input', 'tap', '540', '1360']);
});

it('taps the dialog Wait button rather than an app button with the same label', function () {
    $command = new RecordingCommand;
    $dialog = <<<'XML'
        <hierarchy rotation="0" width="1080" height="2400">
            <node text="Wait" resource-id="com.example:id/snooze" class="android.widget.Button" bounds="[0,200][400,300]" />
            <node text="Wait" resource-id="android:id/aerr_wait" class="android.widget.Button" package="android" bounds="[100,1300][980,1420]" />
        </hierarchy>
        XML;
    $app = '<hierarchy rotation="0" width="1080" height="2400"><node text="Home" class="android.widget.TextView" bounds="[0,0][200,80]" /></hierarchy>';
    $dumps = [$dialog, $app];
    $command->responder = function (string $binary, array $arguments) use (&$dumps): ?string {
        return in_array('uiautomator', $arguments, true) ? array_shift($dumps) : null;
    };
    $driver = androidDriver($command);

    $driver->describe();

    $taps = array_values(array_filter($command->calls, fn (array $call): bool => ($call[1][4] ?? null) === 'tap'));

    expect($taps)->toHaveCount(1)
        ->and($taps[0][1])->toBe(['-s', 'emulator-5554', 'shell', 'input', 'tap', '540', '1360']);
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

    expect($attempts)->toBe(2)
        ->and($command->timeouts['php artisan native:run android emulator-5554 --build=debug --no-tty'])->toBe((float) Command::BUILD_TIMEOUT);
});

it('does not retry a native build that ran out of its own time limit', function () {
    (new ReflectionProperty(AndroidDriver::class, 'built'))->setValue(null, []);

    try {
        (new Command)->run(PHP_BINARY, ['-r', 'sleep(5);'], timeout: 0.1);
    } catch (SimulatorException $timedOut) {
    }

    $command = new RecordingCommand;
    $attempts = 0;
    $command->responder = function (string $binary, array $arguments) use (&$attempts, $timedOut): ?string {
        if ($binary !== 'php' || ! in_array('native:run', $arguments, true)) {
            return null;
        }

        $attempts++;

        throw $timedOut;
    };
    $driver = androidDriver($command);

    expect(fn () => (new ReflectionMethod(AndroidDriver::class, 'buildOnce'))->invoke($driver))
        ->toThrow(SimulatorException::class, 'did not finish within')
        ->and($attempts)->toBe(1);
});

it('gives a long line of typing more time than one tap', function () {
    $command = new RecordingCommand;
    $driver = androidDriver($command);

    $driver->text(str_repeat('a', 120));

    expect(array_values($command->timeouts))->toBe([(float) Command::TIMEOUT + 120]);
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
