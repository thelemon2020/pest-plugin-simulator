<?php

declare(strict_types=1);

use NativePhp\Simulator\AccessibilityTree;
use NativePhp\Simulator\ElementFinder;
use NativePhp\Simulator\Screen;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeDriver;

it('passes when every control has a label', function () {
    $driver = new FakeDriver([[
        ['label' => 'Save', 'role' => 'Button', 'id' => null, 'center' => [200, 500]],
        ['label' => 'Name', 'role' => 'TextField', 'id' => 'name', 'center' => [200, 300]],
        ['label' => 'Dark mode', 'role' => 'Switch', 'id' => null, 'center' => [300, 400]],
        ['label' => '', 'role' => 'Image', 'id' => null, 'center' => [200, 100]],
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->assertAccessible();

    expect($driver->taps)->toBe([]);
});

it('names every control that has no label', function () {
    $driver = new FakeDriver([[
        ['label' => 'Save', 'role' => 'Button', 'id' => null, 'center' => [200, 500]],
        ['label' => '', 'role' => 'Button', 'id' => null, 'center' => [340, 60]],
        ['label' => '', 'role' => 'TextField', 'id' => 'search', 'center' => [200, 120]],
        ['label' => '', 'role' => 'Switch', 'id' => null, 'center' => [300.4, 400.6]],
    ]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0))->assertAccessible())
        ->toThrow(AssertionFailedError::class, "3 controls have no accessibility label, so a screen reader cannot name them:\n- Button at (340, 60)\n- TextField [search] at (200, 120)\n- Switch at (300, 401)");
});

it('waits for a label the app fills in after the screen appears', function () {
    $driver = new FakeDriver([
        [['label' => '', 'role' => 'Button', 'id' => null, 'center' => [340, 60]]],
        [['label' => 'Close', 'role' => 'Button', 'id' => null, 'center' => [340, 60]]],
    ]);

    (new Screen($driver, timeoutSeconds: 2))->assertAccessible();

    expect($driver->taps)->toBe([]);
});

it('leaves out the system photo picker and share sheet', function () {
    $driver = new FakeDriver([[
        ['label' => 'Photos', 'role' => 'StaticText', 'id' => null, 'center' => [200, 60], 'chrome' => 'photos'],
        ['label' => '', 'role' => 'Button', 'id' => null, 'center' => [200, 300], 'chrome' => 'photos'],
        ['label' => '', 'role' => 'Button', 'id' => null, 'center' => [100, 700], 'chrome' => 'share'],
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->assertAccessible();

    expect($driver->taps)->toBe([]);
});

it('checks only the controls within() a section', function () {
    $driver = new FakeDriver([[
        ['label' => 'Settings', 'role' => 'Other', 'id' => 'settings', 'center' => [200, 200], 'frame' => [0, 100, 400, 200]],
        ['label' => 'Dark mode', 'role' => 'Switch', 'id' => null, 'center' => [300, 200]],
        ['label' => '', 'role' => 'Button', 'id' => null, 'center' => [340, 600]],
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->within('settings', fn (Screen $screen) => $screen->assertAccessible());

    expect($driver->taps)->toBe([]);
});

it('takes a label inside a control\'s frame as its name, as TalkBack does', function () {
    $driver = new FakeDriver([[
        ['label' => '', 'role' => 'Switch', 'id' => null, 'center' => [183, 628], 'frame' => [42, 597, 282, 63]],
        ['label' => 'Subscribe', 'role' => 'StaticText', 'id' => null, 'center' => [225, 628], 'frame' => [126, 597, 198, 63]],
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->assertAccessible();

    expect($driver->taps)->toBe([]);
});

it('names an Android checkbox by the text inside it', function () {
    // Captured from the sample app's /controls screen on an Android 15 emulator. Compose
    // reports the checkbox and its "Subscribe" text as two nodes, the text inside the box.
    $elements = AccessibilityTree::summarize((string) file_get_contents(dirname(__DIR__).'/Fixtures/android-controls-checkbox.json'));

    expect((new ElementFinder)->unlabeled($elements))->toBe([]);
});

it('names an Android icon button by the a11y-label laid over it', function () {
    // Captured from the sample app's /unlabeled screen on an Android 15 emulator. The
    // a11y-label is on a node with the button's own frame. The field below has no label.
    $elements = AccessibilityTree::summarize((string) file_get_contents(dirname(__DIR__).'/Fixtures/android-labeled-icon-button.json'));

    expect(array_column((new ElementFinder)->unlabeled($elements), 'role'))->toBe(['TextField']);
});

it('checks an Android select, which reads as a Spinner', function () {
    $elements = [
        ['label' => '', 'role' => 'android.widget.Spinner', 'id' => null, 'center' => [400, 1125], 'frame' => [42.0, 1052.0, 735.0, 147.0]],
    ];

    expect(array_column((new ElementFinder)->unlabeled($elements), 'role'))->toBe(['android.widget.Spinner']);
});

it('does not take another control inside a control as its name', function () {
    // TalkBack does not fold a control that takes focus of its own into its parent's name.
    $elements = [
        ['label' => '', 'role' => 'Button', 'id' => null, 'center' => [540, 400], 'frame' => [0.0, 350.0, 1080.0, 100.0]],
        ['label' => 'Dark mode', 'role' => 'Switch', 'id' => null, 'center' => [960, 400], 'frame' => [900.0, 360.0, 120.0, 80.0]],
    ];

    expect(array_column((new ElementFinder)->unlabeled($elements), 'role'))->toBe(['Button']);
});

it('takes a label that overhangs the control by under a point as its name', function () {
    $elements = [
        ['label' => '', 'role' => 'Switch', 'id' => null, 'center' => [183, 628], 'frame' => [42.0, 597.0, 282.0, 63.0]],
        ['label' => 'Subscribe', 'role' => 'StaticText', 'id' => null, 'center' => [225, 628], 'frame' => [126.0, 596.67, 198.5, 63.0]],
    ];

    expect((new ElementFinder)->unlabeled($elements))->toBe([]);
});

it('leaves a control named by a label inside it out of the listing\'s unlabeled controls', function () {
    $description = (new ElementFinder)->describe([
        ['label' => 'Add', 'role' => 'android.view.View', 'id' => null, 'center' => [118, 492], 'frame' => [42.0, 445.0, 152.0, 95.0]],
        ['label' => '', 'role' => 'Button', 'id' => null, 'center' => [118, 492], 'frame' => [42.0, 445.0, 152.0, 95.0]],
        ['label' => '', 'role' => 'TextField', 'id' => null, 'center' => [540, 670], 'frame' => [42.0, 597.0, 996.0, 147.0]],
    ]);

    expect($description)->toContain('These controls have no accessibility label: TextField.');
});
