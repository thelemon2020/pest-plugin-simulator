<?php

declare(strict_types=1);

use NativePhp\Simulator\AccessibilityTree;
use NativePhp\Simulator\ElementFinder;
use NativePhp\Simulator\Screen;
use Tests\Support\FakeDriver;

it('dismisses the open dialog and then sees the app', function () {
    $driver = new FakeDriver([
        [
            ['label' => 'Open in “CollectShine”?', 'role' => 'StaticText', 'id' => null, 'center' => [200, 400]],
            ['label' => 'Open', 'role' => 'Button', 'id' => null, 'center' => [275, 474]],
            ['label' => 'Cancel', 'role' => 'Button', 'id' => null, 'center' => [120, 474]],
        ],
        [
            ['label' => 'Turn the lights on', 'role' => 'Button', 'id' => null, 'center' => [200, 500]],
        ],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->assertSee('Turn the lights on');

    expect($driver->taps)->toBe([[275.0, 474.0]]);
});

it('taps the center of the named control', function () {
    $driver = new FakeDriver([
        [
            ['label' => 'Discover', 'role' => 'Button', 'id' => null, 'center' => [129.75, 822.0]],
            ['label' => 'Playing', 'role' => 'Button', 'id' => null, 'center' => [50, 822]],
        ],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->tap('Discover');

    expect($driver->taps)->toBe([[129.75, 822.0]]);
});

it('focuses a field and types', function () {
    $driver = new FakeDriver([
        [
            ['label' => 'Email', 'role' => 'TextField', 'id' => null, 'center' => [200, 300]],
        ],
        // settled() needs the value to match on two CONSECUTIVE reads before it trusts it
        // (see its own doc comment), so both of these need the final value.
        [
            ['label' => 'Email', 'role' => 'TextField', 'id' => null, 'center' => [200, 300], 'value' => 'ada@example.com'],
        ],
        [
            ['label' => 'Email', 'role' => 'TextField', 'id' => null, 'center' => [200, 300], 'value' => 'ada@example.com'],
        ],
    ]);

    // A nonzero timeout, unlike every other test in this file: settled()'s confirm-twice
    // loop needs real budget to take a second read at all, which a zero timeout — correct
    // everywhere else, where one read is enough to decide — never allows.
    (new Screen($driver, timeoutSeconds: 1))->type('Email', 'ada@example.com');

    expect($driver->taps)->toBe([[200.0, 300.0]])
        ->and($driver->clears)->toBe(1)
        ->and($driver->texts)->toBe(['ada@example.com']);
});

it('settles typing into a field named only by its placeholder', function () {
    // The placeholder is gone from the tree once text goes in (see ElementFinder::holds()),
    // so without finding the field where it was tapped this burned every attempt.
    $fixtures = dirname(__DIR__).'/Fixtures';
    $driver = new FakeDriver([
        AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-unlabeled-search-field-empty.json')),
        AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-unlabeled-search-field-typed.json')),
    ]);

    (new Screen($driver, timeoutSeconds: 1))->type('Search artist or title…', 'Talk');

    expect($driver->taps)->toBe([[145.0, 156.0]])
        ->and($driver->texts)->toBe(['Talk']);
});

it('settles typing into a secure field that reads back masked', function () {
    $fixtures = dirname(__DIR__).'/Fixtures';
    $driver = new FakeDriver([
        AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-secure-field-empty.json')),
        AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-secure-field-typed.json')),
    ]);

    (new Screen($driver, timeoutSeconds: 1))->type('Password', 'secret');

    expect($driver->taps)->toHaveCount(1)
        ->and($driver->texts)->toBe(['secret']);
});

it('settles typing into an editor named by a placeholder drawn over it', function () {
    $fixtures = dirname(__DIR__).'/Fixtures';
    $driver = new FakeDriver([
        AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-placeholder-editor-empty.json')),
        AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-placeholder-editor-typed.json')),
    ]);

    (new Screen($driver, timeoutSeconds: 1))->type('What should I play tonight?', 'What should I play?');

    expect($driver->taps)->toBe([[201.0, 755.5]])
        ->and($driver->texts)->toBe(['What should I play?']);
});

it('retries up to the attempt limit when the value never settles', function () {
    $driver = new FakeDriver([
        [
            ['label' => 'Email', 'role' => 'TextField', 'id' => null, 'center' => [200, 300]],
        ],
    ]);

    // timeoutSeconds: 0 means settled()'s confirm-twice loop can never take a second read,
    // so it can never confirm — every attempt reads as unsettled, which is exactly the
    // worst case this proves: type() gives up after its own attempt limit rather than
    // looping forever when the field genuinely never reports back correctly.
    (new Screen($driver, timeoutSeconds: 0))->type('Email', 'ada@example.com');

    expect($driver->taps)->toBe([[200.0, 300.0], [200.0, 300.0], [200.0, 300.0]])
        ->and($driver->clears)->toBe(3)
        ->and($driver->texts)->toBe(['ada@example.com', 'ada@example.com', 'ada@example.com']);
});

it('dumps the controls on screen the way a failure lists them, and keeps going', function () {
    $elements = [
        ['label' => 'Save', 'role' => 'Button', 'id' => null, 'center' => [200, 700]],
        ['label' => 'Name', 'role' => 'TextField', 'id' => null, 'center' => [200, 300], 'value' => 'Ada'],
        ['label' => '', 'role' => 'Switch', 'id' => 'lights-toggle', 'center' => [300, 400]],
    ];
    $driver = new FakeDriver([$elements]);

    ob_start();
    $screen = (new Screen($driver, timeoutSeconds: 0))->dump()->tap('Save');
    $printed = ob_get_clean();

    expect($screen)->toBeInstanceOf(Screen::class)
        ->and($printed)->toBe((new ElementFinder)->describe($elements)."\n\n")
        ->and($printed)->toContain("Button: Save\nTextField: Name = Ada\nSwitch: [lights-toggle]")
        ->and($driver->taps)->toBe([[200.0, 700.0]]);
});

it('says when the dump has a WebView in it', function () {
    $driver = new FakeDriver([[
        ['label' => 'Page', 'role' => 'WebView', 'id' => null, 'center' => [200, 400]],
    ]]);

    ob_start();
    (new Screen($driver, timeoutSeconds: 0))->dump();
    $printed = ob_get_clean();

    expect($printed)->toContain('A WebView is on screen.');
});
