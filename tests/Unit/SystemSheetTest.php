<?php

declare(strict_types=1);

use NativePhp\Simulator\AccessibilityTree;
use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Screen;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeDriver;
use Tests\Support\RecordingCommand;

afterEach(function () {
    Configuration::reset();
});

function systemControl(string $label, array $extra = []): array
{
    return array_merge([
        'label' => $label,
        'role' => 'Button',
        'id' => null,
        'center' => [100.0, 200.0],
    ], $extra);
}

it('taps the alert button and not the same label in the app', function () {
    $driver = new FakeDriver([[
        systemControl('Delete', ['center' => [10.0, 10.0]]),
        systemControl('Delete', ['chrome' => 'alert', 'center' => [80.0, 90.0]]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->alert('Delete');

    expect($driver->taps)->toBe([[80.0, 90.0]]);
});

it('fails when the app did not open an alert', function () {
    $driver = new FakeDriver([[
        systemControl('Delete'),
    ]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0))->alert('Delete'))
        ->toThrow(AssertionFailedError::class, 'The alert did not offer [Delete].');
});

it('leaves an alert on screen until the test answers it', function () {
    $driver = new FakeDriver([[
        systemControl('Delete this note?', ['role' => 'StaticText', 'chrome' => 'alert', 'center' => null]),
        systemControl('Delete', ['chrome' => 'alert']),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->assertSee('Delete this note?');

    expect($driver->taps)->toBe([]);
});

it('taps a named share target', function () {
    $driver = new FakeDriver([[
        systemControl('Copy', ['center' => [10.0, 10.0]]),
        systemControl('Copy', ['chrome' => 'share', 'center' => [40.0, 50.0]]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->share('Copy');

    expect($driver->taps)->toBe([[40.0, 50.0]]);
});

it('dismisses a share sheet from its close button', function () {
    $driver = new FakeDriver([[
        systemControl('Close', ['chrome' => 'share', 'center' => [20.0, 30.0]]),
        systemControl('Copy', ['chrome' => 'share', 'center' => [40.0, 50.0]]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->share();

    expect($driver->taps)->toBe([[20.0, 30.0]])
        ->and($driver->backs)->toBe(0);
});

it('dismisses a share sheet with back when it has no close button', function () {
    $driver = new FakeDriver([[
        systemControl('Copy', ['chrome' => 'share']),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->share();

    expect($driver->taps)->toBe([])
        ->and($driver->backs)->toBe(1);
});

it('fails when the share sheet is not open', function () {
    $driver = new FakeDriver([[
        systemControl('Copy'),
    ]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0))->share('Copy'))
        ->toThrow(AssertionFailedError::class, 'The share sheet did not offer [Copy].');
});

it('picks the first image and confirms', function () {
    $driver = new FakeDriver([[
        systemControl('Photo, later', ['role' => 'Image', 'chrome' => 'photos', 'center' => [200.0, 400.0]]),
        systemControl('Photo, first', ['role' => 'Image', 'chrome' => 'photos', 'center' => [40.0, 120.0]]),
        systemControl('Add', ['chrome' => 'photos', 'center' => [300.0, 700.0]]),
        systemControl('Cancel', ['center' => [10.0, 10.0]]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->pickPhoto();

    expect($driver->taps)->toBe([[40.0, 120.0], [300.0, 700.0]]);
});

it('picks an image when the picker has no confirm button', function () {
    $driver = new FakeDriver([[
        systemControl('Photo, May 1', ['role' => 'Image', 'chrome' => 'photos', 'center' => [30.0, 80.0]]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->pickPhoto();

    expect($driver->taps)->toBe([[30.0, 80.0]]);
});

it('cancels the photo picker', function () {
    $driver = new FakeDriver([[
        systemControl('Cancel', ['center' => [10.0, 10.0]]),
        systemControl('Cancel', ['chrome' => 'photos', 'center' => [24.0, 40.0]]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->cancelPhoto();

    expect($driver->taps)->toBe([[24.0, 40.0]]);
});

it('fails when the photo picker is not open', function () {
    $driver = new FakeDriver([[
        systemControl('Cancel'),
    ]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0))->cancelPhoto())
        ->toThrow(AssertionFailedError::class, 'The photo picker had no [Cancel] button.');
});

it('reads an action sheet, a share sheet, and the photo picker', function () {
    $action = AccessibilityTree::summarize((string) json_encode([
        'role' => 'Sheet',
        'AXLabel' => 'Delete post?',
        'children' => [
            ['role' => 'Button', 'AXLabel' => 'Delete', 'AXFrame' => '{{20, 400}, {200, 44}}'],
        ],
    ]));
    $share = AccessibilityTree::summarize((string) json_encode([
        'role' => 'Sheet',
        'AXLabel' => 'Share',
        'children' => [
            ['role' => 'Button', 'AXLabel' => 'Copy', 'AXFrame' => '{{16, 200}, {72, 72}}'],
        ],
    ]));
    $photos = AccessibilityTree::summarize((string) json_encode([
        'role' => 'Sheet',
        'AXLabel' => 'Photos',
        'children' => [
            [
                'role' => 'NavigationBar',
                'AXLabel' => 'Photos',
                'children' => [
                    ['role' => 'Button', 'AXLabel' => 'Cancel', 'AXFrame' => '{{8, 20}, {64, 32}}'],
                ],
            ],
            ['role' => 'Image', 'AXLabel' => 'Photo, May 1', 'AXFrame' => '{{10, 80}, {100, 100}}'],
        ],
    ]));

    $alert = new FakeDriver([$action]);
    (new Screen($alert, timeoutSeconds: 0))->alert('Delete');

    $shared = new FakeDriver([$share]);
    (new Screen($shared, timeoutSeconds: 0))->share('Copy');

    $picker = new FakeDriver([$photos]);
    (new Screen($picker, timeoutSeconds: 0))->cancelPhoto();

    expect($alert->taps)->toBe([[120.0, 422.0]])
        ->and($action[1]['chrome'])->toBe('alert')
        ->and($shared->taps)->toBe([[52.0, 236.0]])
        ->and($share[1]['chrome'])->toBe('share')
        ->and($picker->taps[0][0])->toBe(40.0)
        ->and($photos[2]['chrome'])->toBe('photos');
});

it('keeps an unlabeled gallery image', function () {
    $rows = AccessibilityTree::summarize((string) json_encode([
        [
            'class' => 'android.widget.ImageView',
            'package' => 'com.google.android.providers.media.module',
            'text' => '',
            'bounds' => '[0,100][80,180]',
        ],
    ]));

    expect($rows[0]['label'])->toBe('Photo')
        ->and($rows[0]['role'])->toBe('Image')
        ->and($rows[0]['chrome'])->toBe('photos');
});

it('marks an android alert button from the hierarchy', function () {
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $command = new RecordingCommand;
    $command->output = <<<'XML'
        <hierarchy rotation="0" width="390" height="844">
            <node text="Delete" resource-id="android:id/button1" class="android.widget.Button" package="com.example.app" bounds="[20,400][220,444]" />
            <node text="Save" resource-id="com.example.app:id/save" class="android.widget.Button" package="com.example.app" bounds="[20,100][120,140]" />
        </hierarchy>
        XML;
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    $elements = $driver->describe();

    expect($elements[0]['chrome'])->toBe('alert')
        ->and($elements[1]['chrome'])->toBeNull();
});
