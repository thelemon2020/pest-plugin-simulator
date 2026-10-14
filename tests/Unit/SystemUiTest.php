<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\AndroidSdk;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\IosDriver;
use Tests\Support\RecordingClient;
use Tests\Support\RecordingCommand;

it('stops a photo picker left on top of the android app\'s task', function () {
    // Read off an android-36 emulator after a test left the picker open and the next one
    // stopped the app: the picker is all that is left in the app's task.
    $command = systemUiCommand([
        file_get_contents(__DIR__.'/../Fixtures/android-activities-picker-left.txt'),
        systemUiActivities('17a5c3e', 'com.google.android.apps.nexuslauncher', 1, null),
    ]);

    systemUiAndroidDriver($command)->closeSystemUi(true);

    expect(systemUiCalls($command))->toBe([
        'activities',
        'am force-stop com.google.android.photopicker',
        'activities',
    ]);
});

it('stops each system app stacked over the android app in turn', function () {
    $command = systemUiCommand([
        systemUiActivities('a1', 'com.google.android.permissioncontroller', 8, 'com.example.app'),
        systemUiActivities('b2', 'com.google.android.documentsui', 8, 'com.example.app'),
        systemUiActivities('c3', 'com.example.app', 8, 'com.example.app'),
    ]);

    systemUiAndroidDriver($command)->closeSystemUi(true);

    expect(systemUiCalls($command))->toBe([
        'activities',
        'am force-stop com.google.android.permissioncontroller',
        'activities',
        'am force-stop com.google.android.documentsui',
        'activities',
    ]);
});

it('leaves the android app alone when it is in front', function () {
    $command = systemUiCommand([systemUiActivities('c3', 'com.example.app', 8, 'com.example.app')]);

    systemUiAndroidDriver($command)->closeSystemUi(true);

    expect(systemUiCalls($command))->toBe(['activities']);
});

it('presses back on another app left in the android app\'s task', function () {
    $command = systemUiCommand([
        systemUiActivities('d4', 'com.android.camera2', 8, 'com.example.app'),
        systemUiActivities('c3', 'com.example.app', 8, 'com.example.app'),
    ]);

    systemUiAndroidDriver($command)->closeSystemUi(true);

    expect(systemUiCalls($command))->toBe([
        'activities',
        'input keyevent 4',
        'activities',
    ]);
});

it('waits for back to finish the activity before reading on', function () {
    $command = systemUiCommand([
        systemUiActivities('d4', 'com.android.camera2', 8, 'com.example.app'),
        systemUiActivities('d4', 'com.android.camera2', 8, 'com.example.app'),
        systemUiActivities('c3', 'com.example.app', 8, 'com.example.app'),
    ]);

    systemUiAndroidDriver($command)->closeSystemUi(true);

    expect(systemUiCalls($command))->toBe([
        'activities',
        'input keyevent 4',
        'activities',
        'activities',
    ]);
});

it('leaves the home screen and other apps\' own tasks to the link that opens the app', function (string $dump) {
    $command = systemUiCommand([$dump]);

    systemUiAndroidDriver($command)->closeSystemUi(true);

    expect(systemUiCalls($command))->toBe(['activities']);
})->with([
    'home screen' => [systemUiActivities('e5', 'com.google.android.apps.nexuslauncher', 6, null)],
    'another app' => [systemUiActivities('f6', 'com.android.chrome', 12, 'com.android.chrome')],
]);

it('gives up on what will not close', function () {
    $command = systemUiCommand([systemUiActivities('a1', 'com.google.android.photopicker', 8, 'com.example.app')]);

    systemUiAndroidDriver($command)->closeSystemUi(true);

    expect(array_count_values(systemUiCalls($command)))->toBe([
        'activities' => 6,
        'am force-stop com.google.android.photopicker' => 5,
    ]);
});

it('reads the activity dump of android 11 and older', function () {
    $command = systemUiCommand([
        <<<'DUMP'
              * TaskRecord{8c4e2a1 #23 A=com.example.app U=0 StackId=19 sz=2}
                mResumedActivity: ActivityRecord{51b8a3f u0 com.android.camera2/com.android.camera.CaptureActivity t23}
              * TaskRecord{2f0d61c #2 I=com.google.android.apps.nexuslauncher/.NexusLauncherActivity U=0 StackId=0 sz=1}
            DUMP,
        systemUiActivities('c3', 'com.example.app', 23, 'com.example.app'),
    ]);

    systemUiAndroidDriver($command)->closeSystemUi(true);

    expect(systemUiCalls($command))->toBe([
        'activities',
        'input keyevent 4',
        'activities',
    ]);
});

it('carries on when nothing is resumed or the activity dump cannot be read', function () {
    $command = systemUiCommand(['']);

    systemUiAndroidDriver($command)->closeSystemUi(true);

    $command = new RecordingCommand;
    $command->failures = ['shell' => 'adb: device offline'];

    systemUiAndroidDriver($command)->closeSystemUi(true);

    expect($command->calls)->toHaveCount(1);
});

it('stops the running android app when a test left its own alert open', function () {
    // Read off an android-36 emulator with the sample app's alert open: a window of the app,
    // so the activity in front is the app's own.
    $command = systemUiCommand([systemUiActivities('c3', 'com.example.app', 8, 'com.example.app')]);
    $command->outputs['uiautomator'] = (string) file_get_contents(__DIR__.'/../Fixtures/android-alert-left.xml');

    systemUiAndroidDriver($command)->closeSystemUi(false);

    $calls = array_map(fn (array $call): string => implode(' ', $call[1]), $command->calls);

    expect($calls[0])->toContain('dumpsys activity activities')
        ->and(implode("\n", $calls))->toContain('uiautomator dump')
        ->and(end($calls))->toBe('-s emulator-5554 shell am force-stop com.example.app');
});

it('leaves the running android app alone with nothing of the system on its screen', function () {
    $command = systemUiCommand([systemUiActivities('c3', 'com.example.app', 8, 'com.example.app')]);
    $command->outputs['uiautomator'] = (string) file_get_contents(__DIR__.'/../Fixtures/android-search-field-empty.xml');

    systemUiAndroidDriver($command)->closeSystemUi(false);

    expect(implode("\n", array_map(fn (array $call): string => implode(' ', $call[1]), $command->calls)))
        ->toContain('uiautomator dump')
        ->not->toContain('force-stop');
});

it('reads no android screen when the database copy is about to stop the app', function () {
    $command = systemUiCommand([systemUiActivities('c3', 'com.example.app', 8, 'com.example.app')]);
    $command->outputs['uiautomator'] = (string) file_get_contents(__DIR__.'/../Fixtures/android-alert-left.xml');

    systemUiAndroidDriver($command)->closeSystemUi(true);

    expect(systemUiCalls($command))->toBe(['activities']);
});

it('stops the ios app when a test kept it running and left a picker or an alert over it', function (string $fixture) {
    // Read off an iPhone 17 Pro (iOS 26.5) after a sample app test failed with each one open.
    $client = new RecordingClient;
    $client->trees = [(string) file_get_contents(__DIR__.'/../Fixtures/'.$fixture)];
    $command = new RecordingCommand;

    systemUiIosDriver($command, $client)->closeSystemUi(false);

    expect($command->calls)->toBe([['xcrun', ['simctl', 'terminate', 'UDID', 'com.example.app']]]);
})->with([
    'photo picker' => 'ios-photo-picker-left.json',
    'alert' => 'ios-alert-left.json',
]);

it('leaves the running ios app alone with nothing of the system over it, its own sheet included', function () {
    $client = new RecordingClient;
    $client->trees = [(string) file_get_contents(__DIR__.'/../Fixtures/ios-note-sheet-over-tab-bar.json')];
    $command = new RecordingCommand;

    systemUiIosDriver($command, $client)->closeSystemUi(false);

    expect($command->calls)->toBe([])
        ->and($client->calls)->toHaveCount(1);
});

it('reads nothing on ios when the database copy is about to stop the app, which closes what it presented', function () {
    $client = new RecordingClient;
    $command = new RecordingCommand;

    systemUiIosDriver($command, $client)->closeSystemUi(true);

    expect($client->calls)->toBe([])
        ->and($command->calls)->toBe([]);
});

it('carries on when the ios tree cannot be read or the app has already quit', function () {
    $command = new RecordingCommand;

    systemUiIosDriver($command, new RecordingClient)->closeSystemUi(false);

    expect($command->calls)->toBe([]);

    $client = new RecordingClient;
    $client->trees = [(string) file_get_contents(__DIR__.'/../Fixtures/ios-alert-left.json')];
    $command->failures = ['terminate' => 'Simulator device failed to terminate com.example.app. found nothing to terminate'];

    systemUiIosDriver($command, $client)->closeSystemUi(false);

    expect($command->calls)->toHaveCount(1);
});

/**
 * The lines of `dumpsys activity activities` that AndroidDriver keeps: the task and the
 * activity resumed in it.
 */
function systemUiActivities(string $record, string $package, int $task, ?string $affinity): string
{
    $identity = $affinity === null ? "I={$package}/.Main" : "A=10213:{$affinity}";

    return implode("\n", [
        "  * Task{9e1c2d4 #{$task} type=standard {$identity} U=0 visible=true visibleRequested=true mode=fullscreen translucent=false sz=1}",
        "    topResumedActivity=ActivityRecord{{$record} u0 {$package}/.Main t{$task}}",
        "  ResumedActivity: ActivityRecord{{$record} u0 {$package}/.Main t{$task}}",
        '',
    ]);
}

/**
 * Answers each read of the activities with the next dump, then the last one again.
 *
 * @param  list<string|false>  $dumps
 */
function systemUiCommand(array $dumps): RecordingCommand
{
    $command = new RecordingCommand;
    $reads = 0;
    $command->responder = function (string $binary, array $arguments) use ($dumps, &$reads): ?string {
        if (! str_starts_with($arguments[3] ?? '', 'dumpsys activity activities')) {
            return null;
        }

        return (string) $dumps[min($reads++, count($dumps) - 1)];
    };

    return $command;
}

/**
 * @return list<string>
 */
function systemUiCalls(RecordingCommand $command): array
{
    $adb = AndroidSdk::binary('platform-tools/adb', 'adb');

    return array_map(function (array $call) use ($adb): string {
        expect($call[0])->toBe($adb)
            ->and(array_slice($call[1], 0, 3))->toBe(['-s', 'emulator-5554', 'shell']);
        $shell = implode(' ', array_slice($call[1], 3));

        return str_starts_with($shell, 'dumpsys activity activities') ? 'activities' : $shell;
    }, $command->calls);
}

function systemUiAndroidDriver(RecordingCommand $command): AndroidDriver
{
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    return $driver;
}

function systemUiIosDriver(RecordingCommand $command, RecordingClient $client): IosDriver
{
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');
    (new ReflectionProperty(IosDriver::class, 'client'))->setValue($driver, $client);

    return $driver;
}
