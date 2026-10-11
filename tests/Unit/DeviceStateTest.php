<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\AndroidSdk;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\DeviceState;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Hid;
use NativePhp\Simulator\IosDriver;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Screen;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\Shutdown;
use NativePhp\Simulator\TestDatabase;
use NativePhp\Simulator\Trace;
use Tests\Support\FakeDriver;
use Tests\Support\RecordingClient;
use Tests\Support\RecordingCommand;

afterEach(function () {
    Configuration::reset();
    Sessions::fake(null);
    TestDatabase::fake(null);
    Run::clear();
    Shutdown::reset();
});

it('sets what the test asks for before the screen opens', function () {
    $driver = deviceStateScreen();
    appearance('dark');
    textSize('large');
    location(51.5072, -0.1276);
    fixStatusBar();

    screen('/lights');

    expect($driver->settings)->toBe([
        ['appearance', 'dark'],
        ['textSize', 'large'],
        ['location', [51.5072, -0.1276]],
        ['statusBar', true],
    ])->and(array_slice($driver->events, -5))->toBe([
        ['setting', 'appearance'],
        ['setting', 'textSize'],
        ['setting', 'location'],
        ['setting', 'statusBar'],
        ['open', 'myapp://lights'],
    ]);
});

it('changes nothing when the test asks for nothing', function () {
    $driver = deviceStateScreen();

    screen('/lights');

    expect($driver->settings)->toBe([]);
});

it('puts back what the last test set when the next one does not ask for it', function () {
    $driver = new FakeDriver([]);

    appearance('dark');
    textSize('large');
    fixStatusBar();
    DeviceState::apply($driver, 'ios:1:iPhone');
    nextDeviceStateTest();
    textSize('large');
    DeviceState::apply($driver, 'ios:1:iPhone');

    expect($driver->settings)->toBe([
        ['appearance', 'dark'],
        ['textSize', 'large'],
        ['statusBar', true],
        ['statusBar', false],
        ['appearance', null],
    ]);
});

it('sets a new value when the next test asks for a different one', function () {
    $driver = new FakeDriver([]);

    location(51.5072, -0.1276);
    DeviceState::apply($driver, 'android:1:Pixel');
    nextDeviceStateTest();
    location(48.8566, 2.3522);
    DeviceState::apply($driver, 'android:1:Pixel');

    expect($driver->settings)->toBe([
        ['location', [51.5072, -0.1276]],
        ['location', [48.8566, 2.3522]],
    ]);
});

it('applies a test once, however many screens it opens', function () {
    $driver = deviceStateScreen();
    appearance('dark');

    screen('/lights');
    screen('/again');

    expect($driver->settings)->toBe([['appearance', 'dark']]);
});

it('changes the device at once when asked after the first screen', function () {
    $driver = deviceStateScreen();

    screen('/lights');
    appearance('dark');
    appearance('dark');

    expect($driver->settings)->toBe([['appearance', 'dark']]);
});

it('changes the device from the screen', function () {
    $driver = deviceStateScreen();

    screen('/lights')
        ->appearance('dark')
        ->textSize('largest')
        ->location(-33.8688, 151.2093)
        ->fixStatusBar()
        ->appearance('dark')
        ->appearance('light');

    expect($driver->settings)->toBe([
        ['appearance', 'dark'],
        ['textSize', 'largest'],
        ['location', [-33.8688, 151.2093]],
        ['statusBar', true],
        ['appearance', 'light'],
    ])->and(array_column(Trace::steps(), 'action'))->toBe([
        'open', 'appearance', 'textSize', 'location', 'fixStatusBar', 'appearance', 'appearance',
    ]);
});

it('refuses a screen setting outside a mobile suite', function () {
    (new Screen(new FakeDriver([])))->appearance('dark');
})->throws(SimulatorException::class, 'appearance() only works inside a mobile() suite.');

it('puts every setting back when the run ends', function () {
    $driver = new FakeDriver([]);

    appearance('dark');
    textSize('small');
    location(51.5072, -0.1276);
    fixStatusBar();
    DeviceState::apply($driver, 'ios:1:iPhone');
    $driver->settings = [];
    Shutdown::run();

    expect($driver->settings)->toBe([
        ['statusBar', false],
        ['appearance', null],
        ['textSize', null],
        ['location', null],
    ]);
});

it('puts back the rest when one setting cannot be put back at the end of the run', function () {
    $driver = new FakeDriver([]);
    $driver->failsRestoring = 'appearance';

    appearance('dark');
    textSize('small');
    DeviceState::apply($driver, 'ios:1:iPhone');
    Shutdown::run();

    expect($driver->settings)->toBe([['appearance', 'dark'], ['textSize', 'small'], ['textSize', null]]);
});

it('puts nothing back at the end once the next test has', function () {
    $driver = new FakeDriver([]);

    appearance('dark');
    DeviceState::apply($driver, 'ios:1:iPhone');
    nextDeviceStateTest();
    DeviceState::apply($driver, 'ios:1:iPhone');
    Shutdown::run();

    expect($driver->settings)->toBe([['appearance', 'dark'], ['appearance', null]]);
});

it('puts back a device of the same platform before another one is readied', function () {
    $driver = new FakeDriver([]);
    Sessions::fake($driver);

    appearance('dark');
    DeviceState::apply($driver, 'ios:1:iPhone 17 Pro');
    DeviceState::arrive(new Device('android', 'Pixel', true));
    DeviceState::arrive(new Device('ios', 'iPhone 17 Pro', true));

    expect($driver->settings)->toBe([['appearance', 'dark']]);

    DeviceState::arrive(new Device('ios', 'iPhone 17', true));
    DeviceState::beginTest();
    DeviceState::apply($driver, 'ios:1:iPhone 17 Pro');

    expect($driver->settings)->toBe([['appearance', 'dark'], ['appearance', null], ['appearance', 'dark']]);
});

it('rejects an unknown appearance', function () {
    appearance('dim');
})->throws(SimulatorException::class, 'Unknown appearance [dim]. Choose light or dark.');

it('rejects an unknown text size', function () {
    textSize('huge');
})->throws(SimulatorException::class, 'Unknown text size [huge]. Choose from small, default, large, larger, largest.');

it('rejects a location off the globe', function () {
    location(91.0, 0.0);
})->throws(SimulatorException::class, 'is not a latitude and longitude');

it('names each text size in the platform\'s own terms', function () {
    expect(array_map(fn (string $size): array => [
        DeviceState::textSize('ios', $size),
        DeviceState::textSize('android', $size),
    ], ['small', 'default', 'large', 'larger', 'largest']))->toBe([
        ['extra-small', '0.85'],
        ['large', '1.0'],
        ['extra-large', '1.15'],
        ['extra-extra-extra-large', '1.3'],
        ['accessibility-large', '2.0'],
    ]);
});

it('writes a coordinate without exponents or trailing zeros', function () {
    expect(array_map(DeviceState::number(...), [51.5072, -0.1276, 10.0, -0.0, 0.0000001, -122.084]))
        ->toBe(['51.5072', '-0.1276', '10', '0', '0', '-122.084']);
});

it('sets and puts back the ios appearance', function () {
    $command = new RecordingCommand;
    $command->responder = fn (string $binary, array $arguments): ?string => $arguments === ['simctl', 'ui', 'UDID', 'appearance'] ? "dark\n" : null;
    $driver = deviceStateIosDriver($command);

    $driver->appearance('light');
    $driver->appearance('dark');
    $driver->appearance(null);
    $driver->appearance(null);

    expect($command->calls)->toBe([
        ['xcrun', ['simctl', 'ui', 'UDID', 'appearance']],
        ['xcrun', ['simctl', 'ui', 'UDID', 'appearance', 'light']],
        ['xcrun', ['simctl', 'ui', 'UDID', 'appearance', 'dark']],
        ['xcrun', ['simctl', 'ui', 'UDID', 'appearance', 'dark']],
    ]);
});

it('puts back the default ios text size when simctl cannot say what it was', function () {
    $command = new RecordingCommand;
    $command->responder = fn (string $binary, array $arguments): ?string => $arguments === ['simctl', 'ui', 'UDID', 'content_size'] ? "unknown\n" : null;
    $driver = deviceStateIosDriver($command);

    $driver->textSize('largest');
    $driver->textSize(null);

    expect($command->calls)->toBe([
        ['xcrun', ['simctl', 'ui', 'UDID', 'content_size']],
        ['xcrun', ['simctl', 'ui', 'UDID', 'content_size', 'accessibility-large']],
        ['xcrun', ['simctl', 'ui', 'UDID', 'content_size', 'large']],
    ]);
});

it('sets and clears the ios location', function () {
    $command = new RecordingCommand;
    $driver = deviceStateIosDriver($command);

    $driver->location([51.5072, -0.1276]);
    $driver->location(null);

    expect($command->calls)->toBe([
        ['xcrun', ['simctl', 'location', 'UDID', 'set', '51.5072,-0.1276']],
        ['xcrun', ['simctl', 'location', 'UDID', 'clear']],
    ]);
});

it('fixes and clears the ios status bar', function () {
    $command = new RecordingCommand;
    $driver = deviceStateIosDriver($command);

    $driver->statusBar(true);
    $driver->statusBar(false);

    expect($command->calls)->toBe([
        ['xcrun', [
            'simctl', 'status_bar', 'UDID', 'override',
            '--time', '9:41',
            '--dataNetwork', 'wifi',
            '--wifiMode', 'active',
            '--wifiBars', '3',
            '--cellularMode', 'active',
            '--cellularBars', '4',
            '--operatorName', '',
            '--batteryState', 'charged',
            '--batteryLevel', '100',
        ]],
        ['xcrun', ['simctl', 'status_bar', 'UDID', 'clear']],
    ]);
});

it('presses home on ios and launches the running app again', function () {
    $command = new RecordingCommand;
    $client = new RecordingClient;
    $driver = deviceStateIosDriver($command);
    (new ReflectionProperty(IosDriver::class, 'client'))->setValue($driver, $client);

    $driver->background(0.0);

    expect($client->calls)->toBe([['stream', 'hid', Hid::home()]])
        ->and($command->calls)->toBe([['xcrun', ['simctl', 'launch', 'UDID', 'com.example.app']]]);
});

it('relaunches the ios app in one call', function () {
    $command = new RecordingCommand;
    $driver = deviceStateIosDriver($command);

    $driver->relaunch();

    expect($command->calls)->toBe([['xcrun', ['simctl', 'launch', '--terminate-running-process', 'UDID', 'com.example.app']]]);
});

it('sets and puts back the android night mode', function () {
    $command = new RecordingCommand;
    $command->outputs = ['uimode' => "Night mode: auto\n"];
    $driver = deviceStateAndroidDriver($command);
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    $driver->appearance('dark');
    $driver->appearance(null);

    expect($command->calls)->toBe([
        [$adb, ['-s', 'emulator-5554', 'shell', 'cmd', 'uimode', 'night']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'cmd', 'uimode', 'night', 'yes']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'cmd', 'uimode', 'night', 'auto']],
    ]);
});

it('sets the android font scale and deletes it when it was never set', function () {
    $command = new RecordingCommand;
    $command->outputs = ['font_scale' => "null\n"];
    $driver = deviceStateAndroidDriver($command);
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    $driver->textSize('large');
    $driver->textSize(null);

    expect($command->calls)->toBe([
        [$adb, ['-s', 'emulator-5554', 'shell', 'settings', 'get', 'system', 'font_scale']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'settings', 'put', 'system', 'font_scale', '1.15']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'settings', 'delete', 'system', 'font_scale']],
    ]);
});

it('puts back the android font scale it read', function () {
    $command = new RecordingCommand;
    $command->outputs = ['font_scale' => "1.1\n"];
    $driver = deviceStateAndroidDriver($command);
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    $driver->textSize('small');
    $driver->textSize(null);

    expect($command->calls[2])->toBe([$adb, ['-s', 'emulator-5554', 'shell', 'settings', 'put', 'system', 'font_scale', '1.1']]);
});

it('sends the android location longitude first, and puts back the last fix the device had', function () {
    $command = new RecordingCommand;
    $command->outputs = ['location' => "  gps provider:\n      last location=Location[gps 51.507198,-0.127598 hAcc=5.0 et=+9m26s617ms alt=0.0]\n"];
    $driver = deviceStateAndroidDriver($command);
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    $driver->location([48.8566, 2.3522]);
    $driver->location(null);

    expect($command->calls)->toBe([
        [$adb, ['-s', 'emulator-5554', 'shell', 'dumpsys', 'location']],
        [$adb, ['-s', 'emulator-5554', 'emu', 'geo', 'fix', '2.3522', '48.8566']],
        [$adb, ['-s', 'emulator-5554', 'emu', 'geo', 'fix', '-0.127598', '51.507198']],
    ]);
});

it('puts back where the emulator starts when the device has no gps fix', function () {
    $command = new RecordingCommand;
    $command->outputs = ['location' => "  gps provider:\n      last location=null\n"];
    $driver = deviceStateAndroidDriver($command);
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    $driver->location([51.5072, -0.1276]);
    $driver->location(null);

    expect($command->calls[1])->toBe([$adb, ['-s', 'emulator-5554', 'emu', 'geo', 'fix', '-0.1276', '51.5072']])
        ->and($command->calls[2])->toBe([$adb, ['-s', 'emulator-5554', 'emu', 'geo', 'fix', '-122.084', '37.4219983']]);
});

it('fixes the android status bar in demo mode and leaves it as it was', function () {
    $command = new RecordingCommand;
    $command->outputs = ['sysui_demo_allowed' => "0\n"];
    $driver = deviceStateAndroidDriver($command);
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');
    $demo = 'am broadcast -a com.android.systemui.demo -e command';

    $driver->statusBar(true);
    $driver->statusBar(false);

    expect($command->calls)->toBe([
        [$adb, ['-s', 'emulator-5554', 'shell', 'settings', 'get', 'global', 'sysui_demo_allowed']],
        [$adb, ['-s', 'emulator-5554', 'shell', implode(' >/dev/null; ', [
            'settings put global sysui_demo_allowed 1',
            "{$demo} exit",
            "{$demo} enter",
            "{$demo} clock -e hhmm 0941",
            "{$demo} battery -e level 100 -e plugged false",
            "{$demo} network -e wifi show -e level 4 -e fully true",
            "{$demo} network -e mobile hide",
            "{$demo} notifications -e visible false",
        ]).' >/dev/null']],
        [$adb, ['-s', 'emulator-5554', 'shell', "{$demo} exit >/dev/null; settings put global sysui_demo_allowed '0' >/dev/null"]],
    ]);
});

it('fixes the android status bar again once a night mode or font scale change has redrawn it', function () {
    $command = new RecordingCommand;
    $driver = deviceStateAndroidDriver($command);
    (new ReflectionProperty(AndroidDriver::class, 'redrawSeconds'))->setValue(null, 0.0);

    $driver->statusBar(true);
    $driver->appearance('dark');
    $driver->textSize('large');
    $driver->statusBar(false);
    $driver->appearance(null);

    $entered = fn (array $call): bool => str_contains($call[1][3] ?? '', 'command enter');

    expect(array_map(fn (array $call): string => $entered($call) ? 'demo' : implode(' ', array_slice($call[1], 3)), $command->calls))->toBe([
        'settings get global sysui_demo_allowed',
        'demo',
        'cmd uimode night',
        'cmd uimode night yes',
        'demo',
        'settings get system font_scale',
        'settings put system font_scale 1.15',
        'demo',
        'am broadcast -a com.android.systemui.demo -e command exit >/dev/null; settings delete global sysui_demo_allowed >/dev/null',
        'cmd uimode night no',
    ]);
})->after(fn () => (new ReflectionProperty(AndroidDriver::class, 'redrawSeconds'))->setValue(null, 2.0));

it('waits for the android status bar to be rebuilt before fixing it', function () {
    $command = new RecordingCommand;
    $driver = deviceStateAndroidDriver($command);
    (new ReflectionProperty(AndroidDriver::class, 'redrawSeconds'))->setValue(null, 0.3);

    $driver->appearance('dark');
    $started = microtime(true);
    $driver->statusBar(true);

    expect(microtime(true) - $started)->toBeGreaterThan(0.25);
})->after(fn () => (new ReflectionProperty(AndroidDriver::class, 'redrawSeconds'))->setValue(null, 2.0));

it('presses home on android and brings the app back from its launcher activity', function () {
    $command = new RecordingCommand;
    $driver = deviceStateAndroidDriver($command);
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    $driver->background(0.0);

    expect($command->calls)->toBe([
        [$adb, ['-s', 'emulator-5554', 'shell', 'input', 'keyevent', '3']],
        [$adb, ['-s', 'emulator-5554', 'shell', 'am start -f 0x10200000 -n "$(cmd package resolve-activity --brief -c android.intent.category.LAUNCHER \'com.example.app\' | tail -n 1)"']],
    ]);
});

it('force-stops the android app before starting it again', function () {
    $command = new RecordingCommand;
    $driver = deviceStateAndroidDriver($command);
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    $driver->relaunch();

    expect($command->calls[0])->toBe([$adb, ['-s', 'emulator-5554', 'shell', 'am', 'force-stop', 'com.example.app']])
        ->and($command->calls[1][1][3])->toStartWith('am start');
});

it('fails when the android app does not start', function () {
    $command = new RecordingCommand;
    $command->responder = fn (string $binary, array $arguments): ?string => str_starts_with($arguments[3] ?? '', 'am start')
        ? "Error: Activity class {com.example.app/} does not exist.\n"
        : null;
    $driver = deviceStateAndroidDriver($command);

    $driver->relaunch();
})->throws(SimulatorException::class, 'Could not start [com.example.app]. Error: Activity class');

it('opens another route in the running app', function () {
    $driver = deviceStateScreen();

    screen('/lights')->openUrl('/settings');

    expect($driver->opened)->toBe(['myapp://lights', 'myapp://settings'])
        ->and(Trace::steps()[1])->toMatchArray(['action' => 'openUrl', 'target' => '/settings', 'sent' => [['open', 'myapp://settings']]]);
});

it('backgrounds and relaunches the app without copying the database again', function () {
    $driver = deviceStateScreen();
    $database = tempnam(sys_get_temp_dir(), 'simulator-');
    TestDatabase::fake(fn (): string => $database);
    TestDatabase::begin();

    screen('/lights')->background(2.5)->relaunch();
    screen('/again');

    expect($driver->backgrounds)->toBe([2.5])
        ->and($driver->relaunches)->toBe(1)
        ->and($driver->databases)->toHaveCount(1)
        ->and(array_column(Trace::steps(), 'action'))->toBe(['open', 'background', 'relaunch', 'open']);
});

function deviceStateScreen(): FakeDriver
{
    Configuration::configure(['scheme' => 'myapp', 'bundle_id' => 'com.example.app']);
    $driver = new FakeDriver([]);
    Sessions::fake($driver);
    Run::useDevice(new Device('ios', 'iPhone', true));
    TestDatabase::fake(null);
    DeviceState::beginTest();
    permissions([]);

    return $driver;
}

function nextDeviceStateTest(): void
{
    DeviceState::forgetRequest();
    DeviceState::beginTest();
}

function deviceStateIosDriver(RecordingCommand $command): IosDriver
{
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    return $driver;
}

function deviceStateAndroidDriver(RecordingCommand $command): AndroidDriver
{
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    return $driver;
}
