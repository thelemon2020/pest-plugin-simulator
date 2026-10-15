<?php

declare(strict_types=1);

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Screen;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\TestDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeDriver;

/**
 * The sample app while NativePHP unpacks its Laravel code, then its alerts screen.
 *
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
 */
function firstLaunchScreens(): array
{
    return [
        [['label' => 'Loading…', 'role' => 'StaticText', 'id' => null, 'center' => [195.0, 400.0]]],
        [['label' => 'Delete everything', 'role' => 'Button', 'id' => null, 'center' => [195.0, 300.0]]],
    ];
}

beforeEach(function () {
    $this->failures = sys_get_temp_dir().'/simulator-first-launch-'.uniqid('', true);
});

afterEach(function () {
    if (is_dir($this->failures)) {
        exec('rm -rf '.escapeshellarg($this->failures));
    }
});

it('waits past the timeout for the first screen of a freshly installed app', function () {
    [$loading, $alerts] = firstLaunchScreens();
    $driver = new FakeDriver([$loading, $loading, $loading, $alerts]);

    (new Screen($driver, timeoutSeconds: 0.3, firstLaunchSeconds: 5.0))->tap('Delete everything');

    expect($driver->taps)->toBe([[195.0, 300.0]]);
});

it('keeps the usual timeout when the app was not just installed', function () {
    [$loading, $alerts] = firstLaunchScreens();
    $driver = new FakeDriver([$loading, $loading, $loading, $alerts]);
    $screen = new Screen($driver, timeoutSeconds: 0.3, failureDirectory: $this->failures);

    expect(fn () => $screen->tap('Delete everything'))
        ->toThrow(AssertionFailedError::class, 'Could not find [Delete everything] to tap.');

    expect($driver->taps)->toBe([]);
});

it('says a step was waiting out the first launch when it gave up', function () {
    [$loading] = firstLaunchScreens();
    $screen = new Screen(new FakeDriver([$loading]), timeoutSeconds: 0.1, failureDirectory: $this->failures, firstLaunchSeconds: 0.5);

    expect(fn () => $screen->tap('Delete everything'))
        ->toThrow(AssertionFailedError::class, "This was the app's first launch since it was installed");
});

it('stops allowing for the first launch once its time is up', function () {
    [$loading] = firstLaunchScreens();
    $screen = new Screen(new FakeDriver([$loading]), timeoutSeconds: 0.1, failureDirectory: $this->failures, firstLaunchSeconds: 0.2);
    usleep(300_000);
    $started = microtime(true);

    $message = null;

    try {
        $screen->tap('Delete everything');
    } catch (AssertionFailedError $failure) {
        $message = $failure->getMessage();
    }

    expect($message)->toContain('Could not find [Delete everything] to tap.')
        ->and($message)->not->toContain('first launch')
        ->and(microtime(true) - $started)->toBeLessThan(1.0);
});

describe('screen()', function () {
    beforeEach(function () {
        Configuration::configure(['scheme' => 'myapp', 'timeout' => 0.3]);
        TestDatabase::fake(fn (): ?string => null);
    });

    afterEach(function () {
        Sessions::fake(null);
        TestDatabase::fake(null);
        Configuration::reset();
    });

    mobile(function () {
        it('gives the first screen after an install the first launch to load', function () {
            [$loading, $alerts] = firstLaunchScreens();
            $driver = new FakeDriver([$loading, $loading, $loading, $alerts]);
            $driver->fresh = true;
            Sessions::fake($driver);

            screen('/alerts')->tap('Delete everything');

            expect($driver->taps)->toBe([[195.0, 300.0]])
                ->and($driver->fresh)->toBeFalse();
        });
    });
});
