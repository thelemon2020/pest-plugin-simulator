<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Screen;
use Tests\Support\FakeDriver;
use Tests\Support\RecordingCommand;

afterEach(function () {
    Configuration::reset();
});

/**
 * @return list<array<string, mixed>>
 */
function readAndroid(string $xml): array
{
    $command = new RecordingCommand;
    $command->outputs['uiautomator'] = $xml;
    $driver = new AndroidDriver(new Device('android', 'Pixel', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    return $driver->describe();
}

it('tags the controls in a row the reader says scrolls sideways', function () {
    // Read by the reader from CollectShine's schedule screen on an android-36 emulator.
    // The day chips are a Compose row: a plain View, scrollable, with scroll left and right.
    $elements = readAndroid((string) file_get_contents(dirname(__DIR__).'/Fixtures/android-reader-schedule.xml'));
    $carousels = array_column($elements, 'carousel', 'label');

    expect($carousels['Mon'])->toBe([42.0, 771.0, 996.0, 111.0])
        ->and($carousels['Sat'])->toBe([42.0, 771.0, 996.0, 111.0])
        ->and($carousels['Run this schedule'])->toBeNull();
});

it('tags a platform HorizontalScrollView, but not a scrollable view of unknown direction, in a plain dump', function () {
    $elements = readAndroid(<<<'XML'
        <hierarchy rotation="0">
            <node class="android.widget.FrameLayout" bounds="[0,0][1080,2400]">
                <node class="android.widget.HorizontalScrollView" scrollable="true" bounds="[0,100][1080,200]">
                    <node text="Chip" class="android.widget.TextView" bounds="[20,120][200,180]" />
                </node>
                <node class="android.view.View" scrollable="true" bounds="[0,300][1080,400]">
                    <node text="Card" class="android.widget.TextView" bounds="[20,320][200,380]" />
                </node>
            </node>
        </hierarchy>
        XML);
    $carousels = array_column($elements, 'carousel', 'label');

    expect($carousels['Chip'])->toBe([0.0, 100.0, 1080.0, 100.0])
        ->and($carousels['Card'])->toBeNull();
});

it('scrolls the one sideways row on an Android screen without naming it', function () {
    $schedule = readAndroid((string) file_get_contents(dirname(__DIR__).'/Fixtures/android-reader-schedule.xml'));
    $scrolled = [...$schedule, [
        'label' => 'Holiday', 'role' => 'android.widget.TextView', 'id' => null, 'center' => [900.0, 826.5], 'value' => null,
        'enabled' => true, 'selected' => false, 'checked' => false, 'chrome' => null, 'webview' => false,
        'carousel' => [42.0, 771.0, 996.0, 111.0], 'secure' => false,
    ]];
    $driver = new FakeDriver([$schedule, $scrolled]);
    $driver->viewport = [1080.0, 2400.0];

    (new Screen($driver, timeoutSeconds: 0))->scrollTo('Holiday', 'right');

    [$x1, $y1, $x2, $y2] = $driver->swipes[0];

    expect($driver->swipes)->toHaveCount(1)
        ->and($y1)->toBe(826.5)
        ->and($y2)->toBe(826.5)
        ->and($x1)->toBeGreaterThan($x2)
        ->and(min($x1, $x2))->toBeGreaterThanOrEqual(42.0)
        ->and(max($x1, $x2))->toBeLessThanOrEqual(1038.0);
});
