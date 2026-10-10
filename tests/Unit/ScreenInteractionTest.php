<?php

declare(strict_types=1);

use NativePhp\Simulator\AccessibilityTree;
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
        [control('Save', ['center' => [300.0, 700.0]])], // settle(): still decelerating
        [control('Save', ['center' => [300.0, 500.0]])], // settle(): still decelerating
        [control('Save', ['center' => [300.0, 500.0]])], // settle(): matches the previous read — settled
    ]);

    // A real timeout, so settle() actually polls instead of skipping (it treats <= 0 as
    // the "unit test, no waiting" convention `until()` already uses).
    (new Screen($driver, timeoutSeconds: 1))->scroll('down')->tap('Save');

    expect($driver->taps)->toBe([[300.0, 500.0]]);
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

it('taps a chip in a sheet over a tab bar where it is', function () {
    // CollectShine's note sheet. Its dimming view reaches a screen past every edge, and read
    // as the display it doubled the viewport: the tab bar fell in the top half, and the chip
    // under it was "above the screen".
    $json = (string) file_get_contents(dirname(__DIR__).'/Fixtures/ios-note-sheet-over-tab-bar.json');
    $driver = new FakeDriver([AccessibilityTree::summarize($json)]);
    $driver->viewport = AccessibilityTree::viewport($json);

    (new Screen($driver, timeoutSeconds: 0))->tap('😌 Chill');

    expect($driver->swipes)->toBe([])
        ->and(array_map(fn (float $axis): float => round($axis, 1), $driver->taps[0]))->toBe([59.2, 487.0]);
});

it('waits for a sheet that just opened to finish sliding in before tapping into it', function () {
    // iOS drops a tap while a sheet presents, and the tree already has the sheet where it
    // will stop, so only the time since the sheet first showed up says when it is safe.
    $sheet = AccessibilityTree::summarize((string) file_get_contents(dirname(__DIR__).'/Fixtures/ios-note-sheet-over-tab-bar.json'));
    $driver = new FakeDriver([
        [control('+ Add a note')],
        $sheet,
    ]);
    $screen = new Screen($driver, timeoutSeconds: 5);
    $timed = function (Closure $action): float {
        $start = microtime(true);
        $action();

        return microtime(true) - $start;
    };

    $opening = $timed(fn () => $screen->tap('+ Add a note'));
    $intoSheet = $timed(fn () => $screen->tap('😌 Chill'));
    $again = $timed(fn () => $screen->tap('Cancel'));

    expect($opening)->toBeLessThan(0.2)
        ->and($intoSheet)->toBeGreaterThan(0.7)
        ->and($again)->toBeLessThan(0.2)
        ->and($driver->taps)->toHaveCount(3);
});

it('counts a sheet\'s presentation from the read that first showed it', function () {
    $sheet = AccessibilityTree::summarize((string) file_get_contents(dirname(__DIR__).'/Fixtures/ios-note-sheet-over-tab-bar.json'));
    $driver = new FakeDriver([$sheet]);
    $screen = new Screen($driver, timeoutSeconds: 5);

    $screen->assertSee('How was it?');
    usleep(800_000);
    $start = microtime(true);
    $screen->tap('😌 Chill');

    expect(microtime(true) - $start)->toBeLessThan(0.2);
});

it('does not wait for a sheet in a unit test', function () {
    $sheet = AccessibilityTree::summarize((string) file_get_contents(dirname(__DIR__).'/Fixtures/ios-note-sheet-over-tab-bar.json'));
    $driver = new FakeDriver([[control('+ Add a note')], $sheet]);
    $start = microtime(true);

    (new Screen($driver, timeoutSeconds: 0))->tap('+ Add a note')->tap('😌 Chill');

    expect(microtime(true) - $start)->toBeLessThan(0.2);
});

it('waits for a sheet that just opened before answering it without locate()', function (array $controls, Closure $act, int $taps, int $backs) {
    // A sheet's dimming view, as iOS reads it, is what tells a read a sheet is up.
    $dimming = control('dismiss popup', ['role' => 'UIDimmingView', 'id' => 'PopoverDismissRegion', 'center' => null]);
    $timed = function (array $tree) use ($act): array {
        $driver = new FakeDriver([$tree]);
        $start = microtime(true);
        $act(new Screen($driver, timeoutSeconds: 5));

        return [microtime(true) - $start, $driver];
    };

    [$sliding, $driver] = $timed([$dimming, ...$controls]);
    [$plain] = $timed($controls);

    expect($sliding)->toBeGreaterThan(0.7)
        ->and($driver->taps)->toHaveCount($taps)
        ->and($driver->backs)->toBe($backs)
        ->and($plain)->toBeLessThan(0.2);
})->with([
    'goBack() from a back button' => [
        [control('Back', ['chrome' => 'navigation', 'center' => [24.0, 60.0]])],
        fn (Screen $screen) => $screen->goBack(), 1, 0,
    ],
    'goBack() with the edge swipe' => [
        [control('Note', ['role' => 'StaticText'])],
        fn (Screen $screen) => $screen->goBack(), 0, 1,
    ],
    'alert()' => [
        [control('Delete', ['chrome' => 'alert'])],
        fn (Screen $screen) => $screen->alert('Delete'), 1, 0,
    ],
    'share() to a target' => [
        [control('Copy', ['chrome' => 'share'])],
        fn (Screen $screen) => $screen->share('Copy'), 1, 0,
    ],
    'share() closed from its button' => [
        [control('Close', ['chrome' => 'share']), control('Copy', ['chrome' => 'share'])],
        fn (Screen $screen) => $screen->share(), 1, 0,
    ],
    'share() closed with back' => [
        [control('Copy', ['chrome' => 'share'])],
        fn (Screen $screen) => $screen->share(), 0, 1,
    ],
    'pickPhoto()' => [
        [
            control('Photo, first', ['role' => 'Image', 'chrome' => 'photos', 'center' => [40.0, 120.0]]),
            control('Add', ['chrome' => 'photos', 'center' => [300.0, 700.0]]),
        ],
        fn (Screen $screen) => $screen->pickPhoto(), 2, 0,
    ],
    'cancelPhoto()' => [
        [control('Cancel', ['chrome' => 'photos'])],
        fn (Screen $screen) => $screen->cancelPhoto(), 1, 0,
    ],
]);

it('scrolls a control out from under the home indicator before tapping it', function () {
    // CollectShine's "Use this record", on a pushed screen with no tab bar: its centre is
    // inside the 874-point viewport, but in the home indicator's strip, where a tap lands on
    // nothing.
    $driver = new FakeDriver([
        [control('Back', ['chrome' => 'navigation', 'center' => [38.0, 84.0]]), control('Use this record', ['center' => [201.0, 863.5]])],
        [control('Back', ['chrome' => 'navigation', 'center' => [38.0, 84.0]]), control('Use this record', ['center' => [201.0, 480.0]])],
    ]);
    $driver->viewport = [402.0, 874.0];
    $driver->homeIndicator = 34.0;

    (new Screen($driver, timeoutSeconds: 0))->tap('Use this record');

    expect($driver->swipes)->toHaveCount(1)
        ->and($driver->swipes[0][3])->toBeLessThan($driver->swipes[0][1])
        ->and($driver->taps)->toBe([[201.0, 480.0]]);
});

it('taps a control just above the home indicator where it is', function () {
    $driver = new FakeDriver([[control('Save', ['center' => [201.0, 830.0]])]]);
    $driver->viewport = [402.0, 874.0];
    $driver->homeIndicator = 34.0;

    (new Screen($driver, timeoutSeconds: 0))->tap('Save');

    expect($driver->swipes)->toBe([])
        ->and($driver->taps)->toBe([[201.0, 830.0]]);
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

function card(string $label, float $x, float $y = 306.0): array
{
    // A row of day chips as iOS reads it: a ScrollView 370 wide, inset 16 from each edge.
    return control($label, ['role' => 'StaticText', 'center' => [$x, $y], 'carousel' => [16.0, $y - 21.0, 370.0, 42.0]]);
}

it('drags a carousel sideways inside its own frame to a card past the right edge', function () {
    $driver = new FakeDriver([
        [card('Sun', 427.0)],
        [card('Sun', 300.0)],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->tap('Sun');

    expect($driver->swipes)->toBe([[...Gesture::sideways('right', 16, 386, 306, 0.5), 0.6]])
        ->and($driver->taps)->toBe([[300.0, 306.0]]);
});

it('drags a carousel the other way to a card past the left edge', function () {
    $driver = new FakeDriver([
        [card('Mon', -50.0)],
        [card('Mon', 46.0)],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->press('Mon');

    expect($driver->swipes)->toBe([[...Gesture::sideways('left', 16, 386, 306, 0.5), 0.6]])
        ->and($driver->presses)->toBe([[46.0, 306.0, 0.8]]);
});

it('drags a card the carousel clips, even with its center on the glass', function () {
    $driver = new FakeDriver([
        [card('Sun', 388.0)],
        [card('Sun', 300.0)],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->tap('Sun');

    expect($driver->swipes)->toHaveCount(1)
        ->and($driver->taps)->toBe([[300.0, 306.0]]);
});

it('scrolls a carousel on screen before dragging it sideways', function () {
    $driver = new FakeDriver([
        [card('Sun', 427.0, 1500.0)],
        [card('Sun', 427.0, 400.0)],
        [card('Sun', 300.0, 400.0)],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->tap('Sun');

    expect($driver->swipes)->toBe([
        [...Gesture::scroll('down', 390, 844, 0.5), 0.6],
        [...Gesture::sideways('right', 16, 386, 400, 0.5), 0.6],
    ])->and($driver->taps)->toBe([[300.0, 400.0]]);
});

it('does not drag a control sideways when nothing it sits in scrolls that way', function () {
    // A list row: a sideways drag here would open its swipe actions, not move anything.
    $driver = new FakeDriver([[
        control('Archive', ['center' => [500.0, 300.0]]),
    ]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: sys_get_temp_dir().'/simulator-no-carousel-test'))
        ->tap('Archive'))
        ->toThrow(AssertionFailedError::class, 'Found [Archive] to tap, but it is to the right of the screen, and nothing it sits in scrolls sideways.');

    expect($driver->swipes)->toBe([])
        ->and($driver->taps)->toBe([]);
});

it('says a card stayed past the edge after eight scrolls', function () {
    $driver = new FakeDriver([[card('Sun', 2000.0)]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: sys_get_temp_dir().'/simulator-off-edge-test'))
        ->tap('Sun'))
        ->toThrow(AssertionFailedError::class, 'Found [Sun] to tap, but it stayed to the right of the screen after 8 scrolls.');

    expect($driver->swipes)->toHaveCount(8)
        ->and($driver->taps)->toBe([]);
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

it('scrolls a carousel sideways until a card it has not drawn yet is on screen', function () {
    $driver = new FakeDriver([
        [card('Mon', 46.0), card('Tue', 112.0)],
        [card('Thu', 245.0), card('Fri', 305.0)],
        [card('Sat', 200.0), card('Sun', 300.0)],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->scrollTo('Sun', 'right')->tap('Sun');

    expect($driver->swipes)->toBe([
        [...Gesture::sideways('right', 16, 386, 306), 0.6],
        [...Gesture::sideways('right', 16, 386, 306), 0.6],
    ])->and($driver->taps)->toBe([[300.0, 306.0]]);
});

it('asks which row to drag when more than one scrolls sideways', function () {
    $driver = new FakeDriver([[card('Mon', 46.0), card('Jan', 46.0, 506.0)]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: sys_get_temp_dir().'/simulator-two-carousels-test'))
        ->scrollTo('Dec', 'right'))
        ->toThrow(AssertionFailedError::class, "More than one row on screen scrolls sideways, so scrollTo() cannot tell which row to drag. Name a control on that row: scrollTo('Dec', 'right', 'Item').");

    expect($driver->swipes)->toBe([]);
});

it('drags the row a named control sits in', function () {
    $driver = new FakeDriver([
        [card('Mon', 46.0), card('Jan', 46.0, 506.0)], // finds Jan's row
        [card('Mon', 46.0), card('Jan', 46.0, 506.0)], // no Dec yet
        [card('Mon', 46.0), card('Dec', 300.0, 506.0)],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->scrollTo('Dec', 'right', 'Jan');

    expect($driver->swipes)->toBe([[...Gesture::sideways('right', 16, 386, 506), 0.6]]);
});

it('drags across the whole screen on a named row that is in no carousel', function () {
    // Android's dump says nothing about what scrolls sideways, so the test names the row.
    $driver = new FakeDriver([
        [control('Mon', ['center' => [60.0, 300.0]])], // finds Mon's row
        [control('Mon', ['center' => [60.0, 300.0]])], // no Sun yet
        [control('Sun', ['center' => [300.0, 300.0]])],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->scrollTo('Sun', 'right', 'Mon');

    expect($driver->swipes)->toBe([[...Gesture::sideways('right', 0, 390, 300), 0.6]]);
});

it('says nothing scrolls sideways when scrollTo() has no row to drag', function () {
    $driver = new FakeDriver([[control('Mon')]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: sys_get_temp_dir().'/simulator-no-row-test'))
        ->scrollTo('Sun', 'right'))
        ->toThrow(AssertionFailedError::class, 'Nothing on screen scrolls sideways, so scrollTo() cannot tell which row to drag.');
});

it('refuses a scrollTo() direction that is not up, down, left, or right', function () {
    $driver = new FakeDriver([[control('Save')]]);

    (new Screen($driver, timeoutSeconds: 0))->scrollTo('Save', 'sideways');
})->throws(SimulatorException::class, 'Scroll [sideways] is not up, down, left, or right.');

it('refuses a control to drag from when scrollTo() goes up or down', function () {
    $driver = new FakeDriver([[control('Save')]]);

    (new Screen($driver, timeoutSeconds: 0))->scrollTo('Save', 'down', 'Header');
})->throws(SimulatorException::class, 'scrollTo() drags from a control only to the left or right, not [down].');

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
