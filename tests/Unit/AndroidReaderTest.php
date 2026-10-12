<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\AndroidReader;
use NativePhp\Simulator\AndroidSdk;
use NativePhp\Simulator\Command;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\RunningCommand;
use Tests\Support\RecordingCommand;

afterEach(function () {
    Configuration::reset();
});

/**
 * A reader stub, scripted, and the file it logs each request to.
 *
 * @param  array<string, mixed>  $script
 * @return array{0: Closure(): ?RunningCommand, 1: string, 2: Closure(): int}
 */
function stubReader(array $script): array
{
    $directory = sys_get_temp_dir().'/pest-simulator-reader-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/script.json', json_encode($script));
    $log = $directory.'/requests.log';
    touch($log);
    $starts = 0;

    $start = function () use ($directory, $log, &$starts): ?RunningCommand {
        $starts++;

        return (new Command)->open(PHP_BINARY, [dirname(__DIR__).'/Support/stub-reader.php', $directory.'/script.json', $log]);
    };

    return [$start, $log, function () use (&$starts): int {
        return $starts;
    }];
}

$xml = '<?xml version="1.0"?><hierarchy rotation="0" width="1080" height="2400"><node text="Home" class="android.widget.TextView" bounds="[0,0][200,80]" /></hierarchy>';

it('reads every dump from one process', function () use ($xml) {
    [$start, $log, $starts] = stubReader(['answers' => [$xml]]);
    $reader = new AndroidReader($start, 5, 5);

    try {
        expect($reader->dump())->toBe($xml)
            ->and($reader->dump())->toBe($xml)
            ->and($starts())->toBe(1)
            ->and(file($log, FILE_IGNORE_NEW_LINES))->toBe(['dump', 'dump']);
    } finally {
        $reader->stop();
    }
});

it('reads nothing when no window has focus, and keeps the reader', function () use ($xml) {
    [$start, , $starts] = stubReader(['answers' => [['none' => true], $xml]]);
    $reader = new AndroidReader($start, 5, 5);

    try {
        expect($reader->dump())->toBe('')
            ->and($reader->dump())->toBe($xml)
            ->and($starts())->toBe(1);
    } finally {
        $reader->stop();
    }
});

it('gives up on a reader that never says it is ready, after three tries', function () {
    [$start, , $starts] = stubReader(['ready' => false, 'answers' => ['']]);
    $reader = new AndroidReader($start, 0.3, 5);
    $began = microtime(true);

    $answers = [$reader->dump(), $reader->dump(), $reader->dump(), $reader->dump()];

    expect($answers)->toBe([null, null, null, null])
        ->and($starts())->toBe(3)
        ->and(microtime(true) - $began)->toBeLessThan(10);
});

it('starts a new reader after one stops answering', function () use ($xml) {
    [$start, , $starts] = stubReader(['answers' => [['hang' => true], $xml]]);
    $reader = new AndroidReader($start, 5, 0.3);

    try {
        expect($reader->dump())->toBeNull()
            ->and($reader->dump())->toBe($xml)
            ->and($starts())->toBe(2);
    } finally {
        $reader->stop();
    }
});

it('starts a new reader after one exits', function () use ($xml) {
    [$start, , $starts] = stubReader(['answers' => [['exit' => true], $xml]]);
    $reader = new AndroidReader($start, 5, 5);

    try {
        expect($reader->dump())->toBeNull()
            ->and($reader->dump())->toBe($xml)
            ->and($starts())->toBe(2);
    } finally {
        $reader->stop();
    }
});

it('ships the reader jar', function () {
    $zip = new ZipArchive;

    expect($zip->open(AndroidReader::jar()))->toBeTrue()
        ->and($zip->locateName('classes.dex'))->not->toBeFalse();

    $zip->close();
});

it('reads the Android screen through the reader, and copies the jar to the emulator first', function () use ($xml) {
    [$start] = stubReader(['answers' => [$xml]]);
    $command = new RecordingCommand;
    $command->opened = $start();
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    try {
        $elements = $driver->describe();
    } finally {
        (new ReflectionProperty(AndroidDriver::class, 'reader'))->getValue($driver)?->stop();
    }

    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    expect(array_column($elements, 'label'))->toBe(['Home'])
        ->and($driver->viewport())->toBe([1080.0, 2400.0])
        ->and($command->calls)->toBe([
            [$adb, ['-s', 'emulator-5554', 'push', AndroidReader::jar(), AndroidReader::REMOTE]],
            [$adb, ['-s', 'emulator-5554', 'shell', AndroidReader::launch()]],
        ]);
});

it('reads with uiautomator dump when the reader cannot start', function () {
    $command = new RecordingCommand;
    $command->outputs['uiautomator'] = '<hierarchy rotation="0" width="1080" height="2400"><node text="Home" class="android.widget.TextView" bounds="[0,0][200,80]" /></hierarchy>';
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    $elements = $driver->describe();

    $dumps = array_values(array_filter($command->calls, fn (array $call): bool => in_array('uiautomator', $call[1], true)));

    expect(array_column($elements, 'label'))->toBe(['Home'])
        ->and($dumps)->toHaveCount(1);
});

it('types through the running reader, with the pause before each key and the text in base64', function () {
    [$start, $log, $starts] = stubReader([]);
    $reader = new AndroidReader($start, 5, 5);

    try {
        expect($reader->type("Ada@example.com it's"))->toBeTrue()
            ->and($reader->clear())->toBeTrue()
            ->and($reader->hideKeyboard())->toBeTrue()
            ->and($starts())->toBe(1)
            ->and(file($log, FILE_IGNORE_NEW_LINES))->toBe([
                'type '.AndroidReader::PAUSE.' '.base64_encode("Ada@example.com it's"),
                'clear',
                'hide',
            ]);
    } finally {
        $reader->stop();
    }
});

it('says when the reader could not type or clear, and keeps it running', function () use ($xml) {
    [$start, , $starts] = stubReader(['answers' => [$xml], 'inputs' => ['-1', '-1', 'ok']]);
    $reader = new AndroidReader($start, 5, 5);

    try {
        expect($reader->type('a'))->toBeFalse()
            ->and($reader->clear())->toBeFalse()
            ->and($reader->type('a'))->toBeTrue()
            ->and($reader->dump())->toBe($xml)
            ->and($starts())->toBe(1);
    } finally {
        $reader->stop();
    }
});

it('starts a new reader after one stops answering while it clears', function () {
    [$start, , $starts] = stubReader(['inputs' => [['hang' => true], 'ok']]);
    $reader = new AndroidReader($start, 5, 0.3);

    try {
        expect($reader->clear())->toBeFalse()
            ->and($reader->clear())->toBeTrue()
            ->and($starts())->toBe(2);
    } finally {
        $reader->stop();
    }
});

it('does not type through a reader that has failed three times', function () {
    [$start, , $starts] = stubReader(['ready' => false]);
    $reader = new AndroidReader($start, 0.3, 5);

    expect([$reader->type('a'), $reader->clear(), $reader->hideKeyboard(), $reader->type('a'), $reader->dump()])
        ->toBe([false, false, false, false, null])
        ->and($starts())->toBe(3);
});

it('clears, types, and closes the keyboard through the reader, with no adb shell input', function () {
    [$start, $log] = stubReader([]);
    $command = new RecordingCommand;
    $command->opened = $start();
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    try {
        $driver->clear();
        $driver->text('Ada@example.com');
    } finally {
        (new ReflectionProperty(AndroidDriver::class, 'reader'))->getValue($driver)?->stop();
    }

    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    expect(file($log, FILE_IGNORE_NEW_LINES))->toBe([
        'clear',
        'type '.AndroidReader::PAUSE.' '.base64_encode('Ada@example.com'),
        'hide',
    ])->and($command->calls)->toBe([
        [$adb, ['-s', 'emulator-5554', 'push', AndroidReader::jar(), AndroidReader::REMOTE]],
        [$adb, ['-s', 'emulator-5554', 'shell', AndroidReader::launch()]],
    ]);
});

it('pastes what the reader has no key for, and types the rest through it', function () {
    [$start, $log] = stubReader([]);
    $command = new RecordingCommand;
    $command->opened = $start();
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    try {
        $driver->text("café au\nlait");
    } finally {
        (new ReflectionProperty(AndroidDriver::class, 'reader'))->getValue($driver)?->stop();
    }

    $sent = array_map(fn (array $call): string => implode(' ', array_slice($call[1], 2)), array_slice($command->calls, 2));

    expect(file($log, FILE_IGNORE_NEW_LINES))->toBe([
        'type '.AndroidReader::PAUSE.' '.base64_encode('caf'),
        'type '.AndroidReader::PAUSE.' '.base64_encode(' au'),
        'type '.AndroidReader::PAUSE.' '.base64_encode('lait'),
        'hide',
    ])->and($sent)->toBe([
        "shell cmd clipboard set-text 'é' 2>&1",
        'shell input keyevent 279',
        "shell cmd clipboard set-text '\n' 2>&1",
        'shell input keyevent 279',
    ]);
});

it('types with adb shell input, @ and all, when the reader could not type', function () {
    [$start, $log] = stubReader(['inputs' => ['-1', 'ok']]);
    $command = new RecordingCommand;
    $command->opened = $start();
    $command->outputs['set-text'] = "No shell command implementation.\n";
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    try {
        $driver->text('a@b');
    } finally {
        (new ReflectionProperty(AndroidDriver::class, 'reader'))->getValue($driver)?->stop();
    }

    $sent = array_map(fn (array $call): string => implode(' ', array_slice($call[1], 2)), array_slice($command->calls, 2));

    expect(file($log, FILE_IGNORE_NEW_LINES))->toBe([
        'type '.AndroidReader::PAUSE.' '.base64_encode('a@b'),
        'dump',
        'hide',
    ])->and($sent)->toBe([
        "shell input text 'a'",
        "shell cmd clipboard set-text '@' 2>&1",
        'shell input keyevent 77',
        "shell input text 'b'",
    ]);
});

it('clears from the end with adb shell input when no field is focused for the reader', function () {
    [$start, $log] = stubReader(['inputs' => ['-1', 'ok']]);
    $command = new RecordingCommand;
    $command->opened = $start();
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    try {
        $driver->clear(3);
        $driver->describe();
    } finally {
        (new ReflectionProperty(AndroidDriver::class, 'reader'))->getValue($driver)?->stop();
    }

    $sent = array_map(fn (array $call): string => implode(' ', array_slice($call[1], 2)), array_slice($command->calls, 2));

    expect(file($log, FILE_IGNORE_NEW_LINES))->toBe(['clear', 'hide', 'dump'])
        ->and($sent)->toBe(['shell input keyevent 123 67 67 67']);
});
