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
    ], [
        control('Title', ['role' => 'TextField', 'value' => "Ada's note", 'center' => [20.0, 30.0]]),
    ]]);

    // A nonzero timeout: type()'s settled() check needs real budget to confirm the value
    // on two consecutive reads (see its own doc comment), which a zero timeout never allows.
    (new Screen($driver, timeoutSeconds: 1))
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

it('scrolls a row below the fold on screen before tapping it', function () {
    $driver = new FakeDriver([
        [control('Row 40', ['center' => [100.0, 1500.0]])],
        [control('Row 40', ['center' => [100.0, 500.0]])],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->tap('Row 40');

    expect($driver->swipes)->toBe([[...Gesture::scroll('down', 390, 844, 0.5), 0.6]])
        ->and($driver->taps)->toBe([[100.0, 500.0]]);
});

it('scrolls up to a row above the screen before pressing it', function () {
    $driver = new FakeDriver([
        [control('Row 1', ['center' => [100.0, -400.0]])],
        [control('Row 1', ['center' => [100.0, 300.0]])],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->press('Row 1');

    expect($driver->swipes)->toBe([[...Gesture::scroll('up', 390, 844, 0.5), 0.6]])
        ->and($driver->presses)->toBe([[100.0, 300.0, 0.8]]);
});

it('scrolls a row out from under the tab bar, but taps the tab bar where it is', function () {
    $tabs = [
        control('Back', ['chrome' => 'navigation', 'center' => [24.0, 80.0]]),
        control('Home', ['chrome' => 'tab', 'center' => [60.0, 800.0]]),
    ];
    $driver = new FakeDriver([
        [...$tabs, control('Row 9', ['center' => [100.0, 790.0]])],
        [...$tabs, control('Row 9', ['center' => [100.0, 420.0]])],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->tap('Row 9')->tap('Home')->tap('Back');

    expect($driver->swipes)->toHaveCount(1)
        ->and($driver->swipes[0][3])->toBeLessThan($driver->swipes[0][1])
        ->and($driver->taps)->toBe([[100.0, 420.0], [60.0, 800.0], [24.0, 80.0]]);
});

it('scrolls a row out from under the nav bar', function () {
    $driver = new FakeDriver([
        [control('Notes', ['chrome' => 'navigation', 'center' => [195.0, 80.0]]), control('Row 2', ['center' => [100.0, 90.0]])],
        [control('Notes', ['chrome' => 'navigation', 'center' => [195.0, 80.0]]), control('Row 2', ['center' => [100.0, 400.0]])],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->tap('Row 2');

    expect($driver->swipes)->toHaveCount(1)
        ->and($driver->swipes[0][3])->toBeGreaterThan($driver->swipes[0][1])
        ->and($driver->taps)->toBe([[100.0, 400.0]]);
});

it('scrolls a row on screen before swiping from it', function () {
    $driver = new FakeDriver([
        [control('Row 40', ['center' => [100.0, 1000.0]])],
        [control('Row 40', ['center' => [100.0, 500.0]])],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->swipe('left', 'Row 40');

    expect($driver->swipes)->toBe([
        [...Gesture::scroll('down', 390, 844, 0.5), 0.6],
        [...Gesture::swipe('left', 390, 844, 100, 500), 0.3],
    ]);
});

it('says a row stayed off screen after eight scrolls', function () {
    $driver = new FakeDriver([[
        control('Row 40', ['center' => [100.0, 3000.0]]),
    ]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: sys_get_temp_dir().'/simulator-off-screen-test'))
        ->tap('Row 40'))
        ->toThrow(AssertionFailedError::class, 'Found [Row 40] to tap, but it stayed below the screen after 8 scrolls.');

    expect($driver->swipes)->toHaveCount(8)
        ->and($driver->taps)->toBe([]);
});

it('stops scrolling toward a row when the timeout runs out', function () {
    $driver = new FakeDriver([[
        control('Row 40', ['center' => [100.0, 3000.0]]),
    ]]);
    $start = microtime(true);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0.5, failureDirectory: sys_get_temp_dir().'/simulator-off-screen-timeout-test'))
        ->tap('Row 40'))
        ->toThrow(AssertionFailedError::class, 'Found [Row 40] to tap, but it stayed below the screen');

    expect(microtime(true) - $start)->toBeLessThan(3.0)
        ->and(count($driver->swipes))->toBeGreaterThan(0)->toBeLessThan(8)
        ->and($driver->taps)->toBe([]);
});

it('scrolls until a row the list has not drawn yet is on screen', function () {
    $driver = new FakeDriver([
        [control('Row 1')],
        [control('Row 20')],
        [control('Row 40', ['center' => [100.0, 600.0]])],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->scrollTo('Row 40')->tap('Row 40');

    expect($driver->swipes)->toBe([
        [...Gesture::scroll('down', 390, 844, 0.5), 0.6],
        [...Gesture::scroll('down', 390, 844, 0.5), 0.6],
    ])->and($driver->taps)->toBe([[100.0, 600.0]]);
});

it('scrolls up to a row, and back toward it when it is drawn past the screen', function () {
    $driver = new FakeDriver([
        [control('Row 20')],
        [control('Row 1', ['center' => [100.0, 1200.0]])],
        [control('Row 1', ['center' => [100.0, 400.0]])],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->scrollTo('Row 1', 'up');

    expect($driver->swipes)->toBe([
        [...Gesture::scroll('up', 390, 844, 0.5), 0.6],
        [...Gesture::scroll('down', 390, 844, 0.5), 0.6],
    ]);
});

it('does not scroll to a row that is already on screen', function () {
    $driver = new FakeDriver([[control('Save')]]);

    (new Screen($driver, timeoutSeconds: 0))->scrollTo('Save');

    expect($driver->swipes)->toBe([]);
});

it('says scrollTo() did not find a row after eight scrolls', function () {
    $driver = new FakeDriver([[control('Row 1')]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: sys_get_temp_dir().'/simulator-scroll-to-test'))
        ->scrollTo('Row 40'))
        ->toThrow(AssertionFailedError::class, 'Scrolled down 8 times and did not find [Row 40].');

    expect($driver->swipes)->toHaveCount(8);
});

it('refuses to scrollTo() sideways', function () {
    $driver = new FakeDriver([[control('Save')]]);

    (new Screen($driver, timeoutSeconds: 0))->scrollTo('Save', 'left');
})->throws(SimulatorException::class, 'Scroll [left] is not up or down.');

it('asserts a switch is off', function () {
    $driver = new FakeDriver([[
        control('Flashlight', ['role' => 'Switch', 'checked' => true]),
        control('Wi-Fi', ['role' => 'Switch', 'checked' => false]),
    ]]);
    $screen = new Screen($driver, timeoutSeconds: 0, failureDirectory: sys_get_temp_dir().'/simulator-not-checked-test');

    $screen->assertNotChecked('Wi-Fi');

    expect(fn () => $screen->assertNotChecked('Flashlight'))
        ->toThrow(AssertionFailedError::class, '[Flashlight] was checked.');
});
