<?php

declare(strict_types=1);

use NativePhp\Simulator\Arguments;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\DeviceState;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Screen;
use NativePhp\Simulator\Screenshots;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeDriver;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/simulator-screenshots-'.uniqid('', true);
    mkdir($this->root);
    $this->root = (string) realpath($this->root);
    $this->cwd = getcwd();
    chdir($this->root);
    $this->failures = $this->root.'/failures';
    // A CI runner sets CI, which stops a baseline being saved. Each test sets it itself.
    $this->ci = getenv('CI');
    putenv('CI');
    Run::useDevice(new Device('ios', 'iPhone 17 Pro', true));
    DeviceState::beginTest();
});

afterEach(function () {
    DeviceState::forgetRequest();
    Run::clear();
    Arguments::reset();
    putenv($this->ci === false ? 'CI' : 'CI='.$this->ci);
    chdir($this->cwd);
    exec('rm -rf '.escapeshellarg($this->root));
});

/**
 * A 40x80 PNG in one color, with a square of another color in its top-left corner.
 */
function screenshotImage(string $path, int $square = 0, array $color = [255, 255, 255]): string
{
    $image = imagecreatetruecolor(40, 80);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$color));

    if ($square > 0) {
        imagefilledrectangle($image, 0, 0, $square - 1, $square - 1, imagecolorallocate($image, 0, 0, 0));
    }

    imagepng($image, $path);

    return $path;
}

function screenshotScreen(string $image, string $failures): array
{
    $driver = new FakeDriver([[['label' => 'Home', 'role' => 'StaticText', 'id' => null, 'center' => [10.0, 10.0]]]]);
    $driver->screen = $image;

    return [$driver, new Screen($driver, timeoutSeconds: 0, failureDirectory: $failures)];
}

it('saves a baseline on the first run and passes', function () {
    [$driver, $screen] = screenshotScreen(screenshotImage($this->root.'/now.png'), $this->failures);

    $screen->assertScreenshotMatches();

    $baseline = Screenshots::baseline(Run::device());

    expect($baseline)->toStartWith($this->root.'/tests/.pest/simulator-screenshots/')
        ->and($baseline)->toEndWith('-ios-iphone-17-pro.png')
        ->and(file_get_contents($baseline))->toBe(file_get_contents($this->root.'/now.png'))
        ->and($driver->settings)->toContain(['statusBar', true]);
});

it('passes when the screen matches its baseline', function () {
    [, $screen] = screenshotScreen(screenshotImage($this->root.'/now.png'), $this->failures);
    $screen->assertScreenshotMatches();

    $screen->assertScreenshotMatches();

    expect(true)->toBeTrue();
});

it('fails with how much changed, and saves the changed pixels', function () {
    [$driver, $screen] = screenshotScreen(screenshotImage($this->root.'/before.png'), $this->failures);
    $screen->assertScreenshotMatches();
    $driver->screen = screenshotImage($this->root.'/after.png', square: 20);

    expect(fn () => $screen->assertScreenshotMatches())
        ->toThrow(AssertionFailedError::class, 'The screen differs from the baseline in 12.5% of its pixels, more than 0.1%.');

    expect(is_file($this->failures.'/screenshot-diff.png'))->toBeTrue();
});

it('allows as many changed pixels as the threshold', function () {
    [$driver, $screen] = screenshotScreen(screenshotImage($this->root.'/before.png'), $this->failures);
    $screen->assertScreenshotMatches();
    $driver->screen = screenshotImage($this->root.'/after.png', square: 4);

    $screen->assertScreenshotMatches(threshold: 0.01);

    expect(true)->toBeTrue();
});

it('ignores colors a little apart, the way antialiasing moves them', function () {
    [$driver, $screen] = screenshotScreen(screenshotImage($this->root.'/before.png'), $this->failures);
    $screen->assertScreenshotMatches();
    $driver->screen = screenshotImage($this->root.'/after.png', color: [250, 248, 252]);

    $screen->assertScreenshotMatches();

    expect(true)->toBeTrue();
});

it('fails a screenshot that is not the size of its baseline', function () {
    [$driver, $screen] = screenshotScreen(screenshotImage($this->root.'/before.png'), $this->failures);
    $screen->assertScreenshotMatches();
    $wide = imagecreatetruecolor(80, 80);
    imagepng($wide, $this->root.'/wide.png');
    $driver->screen = $this->root.'/wide.png';

    expect(fn () => $screen->assertScreenshotMatches())
        ->toThrow(AssertionFailedError::class, 'The screenshot is not the size of the baseline');
});

it('keeps one baseline per name in a test', function () {
    [$driver, $screen] = screenshotScreen(screenshotImage($this->root.'/empty.png'), $this->failures);
    $screen->assertScreenshotMatches('empty');
    $driver->screen = screenshotImage($this->root.'/full.png', square: 20);

    $screen->assertScreenshotMatches('full')->assertScreenshotMatches('full');

    expect(Screenshots::baseline(Run::device(), 'full'))->toEndWith('-ios-iphone-17-pro-full.png');
});

it('saves the baseline again with --update-screenshots', function () {
    [$driver, $screen] = screenshotScreen(screenshotImage($this->root.'/before.png'), $this->failures);
    $screen->assertScreenshotMatches();
    $driver->screen = screenshotImage($this->root.'/after.png', square: 20);
    Arguments::intercept(['pest', '--update-screenshots']);

    $screen->assertScreenshotMatches();

    expect(file_get_contents(Screenshots::baseline(Run::device())))->toBe(file_get_contents($this->root.'/after.png'));
});

it('fails on CI when there is no baseline to compare with', function () {
    putenv('CI=true');
    [, $screen] = screenshotScreen(screenshotImage($this->root.'/now.png'), $this->failures);

    expect(fn () => $screen->assertScreenshotMatches())
        ->toThrow(AssertionFailedError::class, 'There is no baseline at');
});

it('refuses a screenshot assertion outside a mobile suite', function () {
    Run::clear();
    [, $screen] = screenshotScreen(screenshotImage($this->root.'/now.png'), $this->failures);

    expect(fn () => $screen->assertScreenshotMatches())
        ->toThrow(SimulatorException::class, 'only works inside a mobile() suite');
});
