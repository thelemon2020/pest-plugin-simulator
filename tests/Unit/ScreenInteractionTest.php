<?php

declare(strict_types=1);

use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Gesture;
use NativePhp\Simulator\Screen;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeDriver;

function control(string $label, array $extra = []): array
{
    return array_merge([
        'label' => $label,
        'role' => 'Button',
        'id' => null,
        'center' => [100.0, 200.0],
    ], $extra);
}

it('scrolls, swipes, and goes back', function () {
    $driver = new FakeDriver([[
        control('Note', ['center' => [40.0, 300.0]]),
    ]]);
    $screen = new Screen($driver, timeoutSeconds: 0);

    $screen->scroll('down')->swipe('down')->swipe('left', 'Note')->goBack();

    expect($driver->swipes[0])->toBe([...Gesture::scroll('down', 390, 844), 0.3])
        ->and($driver->swipes[1])->toBe([...Gesture::swipe('down', 390, 844), 0.3])
        ->and($driver->swipes[2])->toBe([...Gesture::swipe('left', 390, 844, 40, 300), 0.3])
        ->and($driver->backs)->toBe(1);
});

it('waits for a scroll to settle before the next tap reads a position', function () {
    $driver = new FakeDriver([
        [control('Header')], // scroll()'s own pre-swipe read()
        [control('Save', ['center' => [500.0, 700.0]])], // settle(): still decelerating
        [control('Save', ['center' => [500.0, 500.0]])], // settle(): still decelerating
        [control('Save', ['center' => [500.0, 500.0]])], // settle(): matches the previous read — settled
    ]);

    // A real timeout, so settle() actually polls instead of skipping (it treats <= 0 as
    // the "unit test, no waiting" convention `until()` already uses).
    (new Screen($driver, timeoutSeconds: 1))->scroll('down')->tap('Save');

    expect($driver->taps)->toBe([[500.0, 500.0]]);
});

it('rides out a companion that has not stabilized yet when scroll() is the first action', function () {
    $driver = new FakeDriver([[
        control('Note', ['center' => [40.0, 300.0]]),
    ]]);
    // The companion fails to resolve the frontmost app on its first two reads — the
    // window right after screen() has just opened a brand new screen — then recovers.
    $driver->describeFailures = 2;

    (new Screen($driver, timeoutSeconds: 2))->scroll('down');

    expect($driver->swipes)->toBe([[...Gesture::scroll('down', 390, 844), 0.3]]);
});

it('gives up waiting for a scroll to settle rather than hang on a screen that never stops moving', function () {
    $reads = 0;
    $trees = array_map(function () use (&$reads): array {
        $reads++;

        // A fresh coordinate every read — this screen never settles. settle() must still
        // return once its cap elapses, not loop forever.
        return [control('Save', ['center' => [100.0 + $reads, 200.0]])];
    }, range(1, 40));

    $driver = new FakeDriver([[control('Header')], ...$trees]);
    $start = microtime(true);

    (new Screen($driver, timeoutSeconds: 1))->scroll('down');

    expect(microtime(true) - $start)->toBeLessThan(3.0);
});

it('scrolls and swipes a given distance and duration', function () {
    $driver = new FakeDriver([[
        control('Note', ['center' => [200.0, 400.0]]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))
        ->scroll('down', 0.25, 0.15)
        ->swipe('left', 'Note', distance: 0.2, seconds: 0.5);

    expect($driver->swipes[0])->toBe([...Gesture::scroll('down', 390, 844, 0.25), 0.15])
        ->and($driver->swipes[1])->toBe([...Gesture::swipe('left', 390, 844, 200, 400, 0.2), 0.5]);
});

it('holds a finger on a control', function () {
    $driver = new FakeDriver([[
        control('Note', ['center' => [40.0, 300.0]]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->press('Note')->press('Note', 1.5);

    expect($driver->presses)->toBe([[40.0, 300.0, 0.8], [40.0, 300.0, 1.5]])
        ->and($driver->taps)->toBe([]);
});

it('refuses a duration that is not greater than 0', function () {
    $driver = new FakeDriver([[control('Note')]]);

    (new Screen($driver, timeoutSeconds: 0))->swipe('down', seconds: 0);
})->throws(SimulatorException::class, 'Duration [0] is not greater than 0.');

it('taps the navigation back button', function () {
    $driver = new FakeDriver([[
        control('Back', ['chrome' => 'navigation', 'center' => [24.0, 60.0]]),
        control('QR sign in', ['role' => 'StaticText']),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->goBack();

    expect($driver->taps)->toBe([[24.0, 60.0]])
        ->and($driver->backs)->toBe(0);
});

it('taps the android back icon', function () {
    $driver = new FakeDriver([[
        control('arrow_back', ['role' => 'android.widget.TextView', 'center' => [74.5, 220.5]]),
        control('QR sign in', ['role' => 'StaticText']),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->goBack();

    expect($driver->taps)->toBe([[74.5, 220.5]])
        ->and($driver->backs)->toBe(0);
});

it('replaces a field and reads its value', function () {
    $driver = new FakeDriver([[
        control('Title', ['role' => 'TextField', 'value' => 'old', 'center' => [20.0, 30.0]]),
    ], [
        control('Title', ['role' => 'TextField', 'value' => "Ada's note", 'center' => [20.0, 30.0]]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))
        ->type('Title', "Ada's note")
        ->assertValue('Title', "Ada's note");

    expect($driver->clears)->toBe(1)
        ->and($driver->cleared)->toBe(40)
        ->and($driver->texts)->toBe(["Ada's note"]);
});

it('clears a long field before replacing it', function () {
    $value = str_repeat('n', 80);
    $driver = new FakeDriver([[
        control('Title', ['role' => 'TextField', 'value' => $value, 'center' => [20.0, 30.0]]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->type('Title', 'short');

    expect($driver->cleared)->toBe(80);
});

it('writes a screenshot of the current screen', function () {
    $path = sys_get_temp_dir().'/simulator-shot-'.uniqid('', true).'/screen.png';
    $driver = new FakeDriver([[control('Save')]]);

    try {
        (new Screen($driver, timeoutSeconds: 0))->screenshot($path);

        expect(file_get_contents($path))->toBe('png');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }

        $directory = dirname($path);

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('saves the screen when a label matches more than one control', function () {
    $directory = sys_get_temp_dir().'/simulator-ambiguous-'.uniqid('', true);
    $driver = new FakeDriver([[
        control('Save', ['center' => [10.0, 20.0]]),
        control('Save', ['center' => [30.0, 40.0]]),
    ]]);

    try {
        expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: $directory))->tap('Save'))
            ->toThrow(AssertionFailedError::class, 'matches more than one Button');

        expect(is_file($directory.'/tree.json'))->toBeTrue()
            ->and(is_file($directory.'/screen.png'))->toBeTrue();
    } finally {
        if (is_dir($directory)) {
            array_map('unlink', glob($directory.'/*') ?: []);
            rmdir($directory);
        }
    }
});

it('reads chrome, tabs, and control state', function () {
    $driver = new FakeDriver([[
        control('Notes', ['chrome' => 'navigation', 'center' => null]),
        control('Home', ['selected' => true, 'chrome' => 'tab', 'id' => '/notes']),
        control('Flashlight', ['role' => 'Switch', 'checked' => true]),
        control('Save', ['enabled' => false]),
        control('😌 Chill', ['selected' => true]),
        control('🔥 Hyped', ['selected' => false]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))
        ->assertNavTitle('Notes')
        ->assertTabActive('Home')
        ->assertNavigatedTo('/notes')
        ->assertNavigatedTo('/media/notes')
        ->assertChecked('Flashlight')
        ->assertDisabled('Save')
        ->assertEnabled('Home')
        ->assertSelected('😌 Chill')
        ->assertNotSelected('🔥 Hyped');
});

it('names an unlabeled control when a tap misses', function () {
    $driver = new FakeDriver([[
        control('Home'),
        control('', ['id' => 'save-button', 'center' => [8.0, 9.0]]),
    ]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: sys_get_temp_dir().'/simulator-unlabeled-test'))
        ->tap('Save'))
        ->toThrow(AssertionFailedError::class, 'These controls have no accessibility label: Button [save-button].');
});

it('taps a control by its accessibility id', function () {
    $driver = new FakeDriver([[
        control('', ['id' => 'save-button', 'center' => [8.0, 9.0]]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->tap('save-button');

    expect($driver->taps)->toBe([[8.0, 9.0]]);
});

it('reads the device once when several labels are checked together', function () {
    $driver = new FakeDriver([[
        control('Save'),
        control('Name'),
    ]]);

    (new Screen($driver, timeoutSeconds: 1))
        ->assertSee('Save', 'Name');

    expect($driver->descriptions)->toBe(1);
});

it('waits until one read contains every label', function () {
    $driver = new FakeDriver([
        [control('Save')],
        [control('Save'), control('Name')],
    ]);

    (new Screen($driver, timeoutSeconds: 1))
        ->assertSee('Save', 'Name');

    expect($driver->descriptions)->toBe(2);
});

it('names the labels missing from the last read', function () {
    $driver = new FakeDriver([[
        control('Save'),
    ]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: sys_get_temp_dir().'/simulator-see-several-test'))
        ->assertSee('Save', 'Name'))
        ->toThrow(AssertionFailedError::class, 'Did not see [Name].');
});

it('names every requested label when the last read has none of them', function () {
    $driver = new FakeDriver([[
        control('Home'),
    ]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: sys_get_temp_dir().'/simulator-see-none-test'))
        ->assertSee('Save', 'Name'))
        ->toThrow(AssertionFailedError::class, 'Did not see [Save], [Name].');
});

it('reads the device again for the next assertion', function () {
    $driver = new FakeDriver([[
        control('Save'),
        control('Name'),
    ]]);

    (new Screen($driver, timeoutSeconds: 1))
        ->assertSee('Save')
        ->assertSee('Name');

    expect($driver->descriptions)->toBe(2);
});

it('reads the device again before a tap so the coordinate is current', function () {
    $driver = new FakeDriver([
        [control('Save', ['center' => [4.0, 5.0]])],
        [control('Save', ['center' => [10.0, 20.0]])],
        [control('Saved')],
    ]);

    (new Screen($driver, timeoutSeconds: 1))
        ->assertSee('Save')
        ->tap('Save')
        ->assertSee('Saved');

    expect($driver->descriptions)->toBe(3)
        ->and($driver->taps)->toBe([[10.0, 20.0]]);
});

it('says when the screen is a web view', function () {
    $driver = new FakeDriver([[
        control('Page', ['role' => 'WebView', 'webview' => true]),
    ]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: sys_get_temp_dir().'/simulator-webview-test'))
        ->assertSee('Welcome'))
        ->toThrow(AssertionFailedError::class, 'WebView');
});
