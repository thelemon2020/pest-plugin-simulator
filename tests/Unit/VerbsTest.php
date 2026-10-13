<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\ElementFinder;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Screen;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeDriver;
use Tests\Support\RecordingCommand;

beforeEach(function () {
    $this->failures = sys_get_temp_dir().'/simulator-verbs-'.uniqid('', true);
});

afterEach(function () {
    Configuration::reset();

    if (is_dir($this->failures)) {
        array_map('unlink', glob($this->failures.'/*') ?: []);
        rmdir($this->failures);
    }
});

function verb(string $label, string $role, float $x, float $y, array $extra = []): array
{
    return array_merge(['label' => $label, 'role' => $role, 'id' => null, 'center' => [$x, $y]], $extra);
}

/**
 * @return list<array<string, mixed>>
 */
function readAndroidControls(): array
{
    $command = new RecordingCommand;
    $command->outputs['uiautomator'] = (string) file_get_contents(dirname(__DIR__).'/Fixtures/android-reader-controls.xml');
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    return $driver->describe();
}

it('turns a switch on, tapping only while it is off, and waits until it reads on', function () {
    $driver = new FakeDriver([
        [verb('Wi-Fi', 'Switch', 350.0, 150.0, ['checked' => false])],
        [verb('Wi-Fi', 'Switch', 350.0, 150.0, ['checked' => true])],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->toggle('Wi-Fi', true);

    expect($driver->taps)->toBe([[350.0, 150.0]]);
});

it('leaves a switch alone when it already reads what toggle() asks for', function () {
    $driver = new FakeDriver([[verb('Wi-Fi', 'Switch', 350.0, 150.0, ['checked' => true])]]);

    (new Screen($driver, timeoutSeconds: 0))->toggle('Wi-Fi', true);

    expect($driver->taps)->toBe([]);
});

it('flips a switch when toggle() is not told which way', function () {
    $driver = new FakeDriver([
        [verb('Wi-Fi', 'Switch', 350.0, 150.0, ['checked' => true])],
        [verb('Wi-Fi', 'Switch', 350.0, 150.0, ['checked' => false])],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->toggle('Wi-Fi');

    expect($driver->taps)->toBe([[350.0, 150.0]]);
});

it('says when a switch did not change', function () {
    $driver = new FakeDriver([[verb('Wi-Fi', 'Switch', 350.0, 150.0, ['checked' => false])]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: $this->failures))->toggle('Wi-Fi', true))
        ->toThrow(AssertionFailedError::class, '[Wi-Fi] stayed off.');
});

it('checks and unchecks a checkbox', function () {
    $driver = new FakeDriver([
        [verb('Subscribe', 'Switch', 70.0, 230.0, ['checked' => false])],
        [verb('Subscribe', 'Switch', 70.0, 230.0, ['checked' => true])],
        [verb('Subscribe', 'Switch', 70.0, 230.0, ['checked' => true])],
        [verb('Subscribe', 'Switch', 70.0, 230.0, ['checked' => false])],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->check('Subscribe')->uncheck('Subscribe');

    expect($driver->taps)->toBe([[70.0, 230.0], [70.0, 230.0]]);
});

it('chooses the option the picker opened, not its own label for the current one', function () {
    $closed = [
        verb('Size', 'Button', 200.0, 410.0, ['value' => 'Medium']),
        verb('Medium', 'StaticText', 200.0, 410.0),
    ];
    $driver = new FakeDriver([$closed, [
        ...$closed,
        verb('Small', 'Button', 200.0, 300.0),
        verb('Medium', 'Button', 200.0, 340.0),
        verb('Large', 'Button', 200.0, 380.0),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->select('Size', 'Medium');

    expect($driver->taps)->toBe([[200.0, 410.0], [200.0, 340.0]]);
});

it('says when the picker never offered the option', function () {
    $driver = new FakeDriver([[verb('Size', 'Button', 200.0, 410.0)]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: $this->failures))->select('Size', 'Huge'))
        ->toThrow(AssertionFailedError::class, '[Size] did not offer [Huge].');
});

it('picks the radio option under its group, not one with the same name further down', function () {
    $options = fn (bool $picked): array => [
        verb('Plan', 'StaticText', 30.0, 100.0),
        verb('Free', 'Button', 50.0, 130.0, ['selected' => ! $picked]),
        verb('Pro', 'Button', 50.0, 160.0, ['selected' => $picked]),
        verb('Billing', 'StaticText', 30.0, 300.0),
        verb('Pro', 'Button', 50.0, 330.0),
    ];
    $driver = new FakeDriver([$options(false), $options(true)]);

    (new Screen($driver, timeoutSeconds: 0))->selectRadio('Plan', 'Pro');

    expect($driver->taps)->toBe([[50.0, 160.0]]);
});

it('changes the tab and waits until it is the active one', function () {
    $tabs = fn (string $active): array => [
        verb('Overview', 'Button', 60.0, 120.0, ['selected' => $active === 'Overview']),
        verb('Activity', 'Button', 200.0, 120.0, ['selected' => $active === 'Activity']),
    ];
    $driver = new FakeDriver([$tabs('Overview'), $tabs('Activity')]);

    (new Screen($driver, timeoutSeconds: 0))->changeTab('Activity');

    expect($driver->taps)->toBe([[200.0, 120.0]]);
});

it('drags an iOS sheet down by its grabber and waits until it has gone', function () {
    $open = [
        verb('dismiss popup', 'UIDimmingView', 201.0, 437.0, ['id' => 'PopoverDismissRegion']),
        verb('Open sheet', 'Button', 70.0, 184.0),
        verb('dismiss popup', 'UIDimmingView', 201.0, 437.0, ['id' => 'PopoverDismissRegion']),
        verb('Sheet Grabber', 'Button', 201.0, 422.0),
        verb('Choose', 'Button', 62.0, 476.0),
    ];
    $driver = new FakeDriver([$open, [verb('Open sheet', 'Button', 70.0, 184.0)]]);

    (new Screen($driver, timeoutSeconds: 0))->dismissSheet();

    expect($driver->swipes)->toBe([[201.0, 422.0, 201.0, 844.0 * 0.95, 0.4]])
        ->and($driver->backs)->toBe(0);
});

it('presses back to close a sheet that has no grabber', function () {
    $driver = new FakeDriver([[verb('Choose', 'Button', 157.0, 1513.0)]]);

    (new Screen($driver, timeoutSeconds: 0))->dismissSheet();

    expect($driver->backs)->toBe(1);
});

it('types and then presses return to submit a field', function () {
    $driver = new FakeDriver([[verb('Search', 'TextField', 200.0, 100.0, ['value' => ''])]]);

    (new Screen($driver, timeoutSeconds: 0))->submit('Search');

    expect($driver->taps)->toBe([[200.0, 100.0]])
        ->and(end($driver->events))->toBe(['enter']);
});

it('taps a slider that jumps to where it is touched', function () {
    $slider = fn (string $value): array => [
        verb('Volume', 'Slider', 200.0, 120.0, ['value' => $value, 'frame' => [0.0, 100.0, 400.0, 40.0], 'range' => [0.0, 100.0]]),
    ];
    $driver = new FakeDriver([$slider('20'), $slider('75')]);

    (new Screen($driver, timeoutSeconds: 0))->slide('Volume', 75);

    // The value sits 20 points in, plus 75 units of 3.6 points.
    expect($driver->taps)->toBe([[290.0, 120.0]])
        ->and($driver->swipes)->toBe([]);
});

it('drags a slider that ignores a tap, from where its thumb is to where the value sits', function () {
    $slider = fn (string $value): array => [
        verb('Volume', 'Slider', 200.0, 120.0, ['value' => $value, 'frame' => [0.0, 100.0, 400.0, 40.0], 'range' => [0.0, 100.0]]),
    ];
    $driver = new FakeDriver([$slider('20'), $slider('20'), $slider('75')]);

    (new Screen($driver, timeoutSeconds: 0))->slide('Volume', 75);

    // Thumb inset by half the height: 20 + 20 * 3.6 = 92, then 55 more units of 3.6.
    expect($driver->swipes)->toBe([[92.0, 120.0, 290.0, 120.0, 0.5]]);
});

it('aims the next drag from where the last one let go', function () {
    $slider = fn (string $value): array => [
        verb('Volume', 'Slider', 200.0, 105.0, ['value' => $value, 'frame' => [0.0, 100.0, 400.0, 10.0], 'range' => [0.0, 100.0]]),
    ];
    $driver = new FakeDriver([$slider('20'), $slider('20'), $slider('70'), $slider('75')]);

    (new Screen($driver, timeoutSeconds: 0))->slide('Volume', 75);

    // It let go at 297.5 with the value at 70: 5 more, at the track's 3.9 points a unit.
    expect($driver->swipes)->toHaveCount(2)
        ->and($driver->swipes[1][0])->toBe(297.5)
        ->and($driver->swipes[1][2])->toEqualWithDelta(317.0, 0.001);
});

it('backs away before a correction too short to count as a drag', function () {
    $slider = fn (string $value): array => [
        verb('Volume', 'Slider', 200.0, 120.0, ['value' => $value, 'frame' => [0.0, 100.0, 400.0, 40.0], 'range' => [0.0, 100.0]]),
    ];
    // The tap does nothing; 74 after the first drag, 61 after backing away 48 points, then 75.
    $driver = new FakeDriver([$slider('20'), $slider('20'), $slider('74'), $slider('61'), $slider('75')]);

    (new Screen($driver, timeoutSeconds: 0))->slide('Volume', 75);

    expect($driver->swipes)->toHaveCount(3)
        ->and(array_slice($driver->swipes[1], 0, 3))->toBe([290.0, 120.0, 242.0])
        ->and($driver->swipes[2][0])->toBe(242.0)
        ->and($driver->swipes[2][2] - $driver->swipes[2][0])->toBeGreaterThan(24.0);
});

it('waits for a slider that drops out of one read after it moved', function () {
    $slider = fn (string $value): array => [
        verb('Volume', 'Slider', 200.0, 120.0, ['value' => $value, 'frame' => [0.0, 100.0, 400.0, 40.0], 'range' => [0.0, 100.0]]),
    ];
    $driver = new FakeDriver([$slider('20'), $slider('75'), [verb('Saving', 'StaticText', 200.0, 60.0)], $slider('75')]);

    (new Screen($driver, timeoutSeconds: 2))->slide('Volume', 75);

    expect($driver->taps)->toBe([[290.0, 120.0]]);
});

it('does not stop a step short of the value', function () {
    $slider = fn (string $value): array => [
        verb('Volume', 'Slider', 200.0, 120.0, ['value' => $value, 'frame' => [0.0, 100.0, 400.0, 40.0], 'range' => [0.0, 100.0]]),
    ];
    $driver = new FakeDriver([$slider('20'), $slider('76')]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: $this->failures))->slide('Volume', 75))
        ->toThrow(AssertionFailedError::class, '[Volume] stopped at 76, not 75.');
});

it('asks for the range of a slider that does not report one', function () {
    $driver = new FakeDriver([[verb('Volume', 'Slider', 200.0, 120.0, ['value' => '20.00', 'frame' => [0.0, 100.0, 400.0, 40.0]])]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0))->slide('Volume', 75))
        ->toThrow(SimulatorException::class, "Pass the slider's min and max: slide('Volume', 50, min: 0, max: 100).");

    (new Screen($driver, timeoutSeconds: 0))->slide('Volume', 20, min: 0, max: 100);

    expect($driver->swipes)->toBe([]);
});

it('refuses a value outside the slider', function () {
    $driver = new FakeDriver([[verb('Volume', 'Slider', 200.0, 120.0, ['value' => '20', 'frame' => [0.0, 100.0, 400.0, 40.0], 'range' => [0.0, 100.0]])]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0))->slide('Volume', 120))
        ->toThrow(SimulatorException::class, "[120] is outside [Volume]'s range of 0 to 100.");
});

it('keeps the names NativePHP\'s own harness uses', function () {
    $driver = new FakeDriver([[
        verb('Photo', 'Button', 100.0, 200.0),
        verb('Name', 'TextField', 100.0, 300.0, ['value' => '']),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->longPress('Photo')->input('Name', '')->pressBack();

    expect($driver->presses)->toHaveCount(1)
        ->and($driver->backs)->toBe(1);
});

it('reads a compose toggle, checkbox, slider, and radio row on android', function () {
    $elements = readAndroidControls();
    $finder = new ElementFinder;
    $volume = $finder->match($elements, 'Volume');

    expect($finder->isChecked($elements, 'Notifications', true))->toBeTrue()
        ->and($finder->match($elements, 'Notifications')['checkable'])->toBeTrue()
        ->and($finder->isChecked($elements, 'Subscribe', false))->toBeTrue()
        ->and($volume['value'])->toBe('20.0')
        ->and($volume['range'])->toBe([0.0, 100.0])
        ->and($finder->isSelected($elements, 'Free', true))->toBeTrue()
        ->and($finder->isSelected($elements, 'Pro', false))->toBeTrue();
});

it('takes matches drawn at one spot as one control', function () {
    $finder = new ElementFinder;

    $match = $finder->match([
        verb('Activity', 'android.view.View', 540.0, 367.0),
        verb('Activity', 'android.widget.TextView', 540.0, 366.5),
    ], 'Activity');

    expect($match['center'])->toBe([540.0, 367.0]);
});

it('finds the top of an ios sheet after the dimming view it lays over the screen', function () {
    $top = (new ElementFinder)->sheetTop([
        verb('Open sheet', 'Button', 70.0, 184.0),
        verb('dismiss popup', 'UIDimmingView', 201.0, 437.0, ['id' => 'PopoverDismissRegion']),
        verb('Choose', 'Button', 62.0, 476.0),
        verb('Grabber', 'Button', 201.0, 422.0),
    ]);

    expect($top['center'] ?? null)->toBe([201.0, 422.0])
        ->and((new ElementFinder)->sheetTop([verb('Open sheet', 'Button', 70.0, 184.0)]))->toBeNull();
});

it('taps a field named by its placeholder again where it was, once text has replaced the name', function () {
    $empty = [verb('Search…', 'TextField', 215.0, 395.0, ['value' => ''])];
    $driver = new FakeDriver([$empty, $empty, [verb('Talk', 'TextField', 215.0, 395.0, ['value' => 'Talk'])]]);

    (new Screen($driver, timeoutSeconds: 0))->submit('Search…', 'Talk');

    expect(end($driver->taps))->toBe([215.0, 395.0])
        ->and(end($driver->events))->toBe(['enter']);
});

it('aims by the line through the last two places a drag let go', function () {
    // A Compose slider jumps to where the finger lands: what counts is where each drag let
    // go. 290 left 74 and 242 left 62, so a unit is 4 points, and 75 is at 294.
    $slider = fn (string $value): array => [
        verb('Volume', 'Slider', 200.0, 120.0, ['value' => $value, 'frame' => [0.0, 100.0, 400.0, 40.0], 'range' => [0.0, 100.0]]),
    ];
    $driver = new FakeDriver([$slider('20'), $slider('20'), $slider('74'), $slider('62'), $slider('75')]);

    (new Screen($driver, timeoutSeconds: 0))->slide('Volume', 75);

    expect($driver->swipes)->toHaveCount(3)
        ->and($driver->swipes[2][0])->toBe(242.0)
        ->and($driver->swipes[2][2])->toEqualWithDelta(294.0, 0.001);
});
