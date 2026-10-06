<?php

declare(strict_types=1);

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
