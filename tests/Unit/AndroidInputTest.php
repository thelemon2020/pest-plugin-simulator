<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use Tests\Support\RecordingCommand;

afterEach(function () {
    Configuration::reset();
});

it('types punctuation and a newline through adb', function () {
    $command = new RecordingCommand;
    $driver = androidDriver($command);

    $driver->text("a+b:c'd");
    $driver->text("a b\nc");

    $typed = array_map(
        fn (array $call): string => implode(' ', array_slice($call[1], 4)),
        $command->calls,
    );

    expect($typed)->toBe([
        'text a',
        'text +',
        'text b',
        'text :',
        'text c',
        "text '",
        'text d',
        'text a',
        'text %s',
        'text b',
        'keyevent 66',
        'text c',
    ]);
});

it('clears by deleting from the end of the field', function () {
    $command = new RecordingCommand;
    $driver = androidDriver($command);

    $driver->clear();
    $driver->back();

    expect($command->calls[0][1])->toBe([
        '-s', 'emulator-5554', 'shell', 'input', 'keyevent', '123',
        ...array_fill(0, 40, '67'),
    ])->and($command->calls[1][1])->toBe(['-s', 'emulator-5554', 'shell', 'input', 'keyevent', '4']);
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

function androidDriver(RecordingCommand $command): AndroidDriver
{
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    return $driver;
}
