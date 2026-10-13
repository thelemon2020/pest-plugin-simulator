<?php

declare(strict_types=1);

use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Screen;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeDriver;

function scopedControl(string $label, float $x, float $y, array $extra = []): array
{
    return array_merge([
        'label' => $label,
        'role' => 'Button',
        'id' => null,
        'center' => [$x, $y],
    ], $extra);
}

/**
 * Two order cards, each with its own Pay button.
 *
 * @return list<array<string, mixed>>
 */
function orderCards(): array
{
    return [
        scopedControl('Order 1', 195.0, 150.0, ['role' => 'Other', 'frame' => [0.0, 100.0, 390.0, 100.0]]),
        scopedControl('Pay', 300.0, 170.0),
        scopedControl('Order 2', 195.0, 300.0, ['role' => 'Other', 'frame' => [0.0, 250.0, 390.0, 100.0]]),
        scopedControl('Pay', 300.0, 320.0),
    ];
}

beforeEach(function () {
    $this->failures = sys_get_temp_dir().'/simulator-within-'.uniqid('', true);
});

afterEach(function () {
    if (is_dir($this->failures)) {
        array_map('unlink', glob($this->failures.'/*') ?: []);
        rmdir($this->failures);
    }
});

it('taps the nth match in reading order', function () {
    $driver = new FakeDriver([[
        scopedControl('Delete', 300.0, 300.0),
        scopedControl('Delete', 300.0, 100.0),
        scopedControl('Delete', 300.0, 200.0),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->nth(2)->tap('Delete');

    expect($driver->taps)->toBe([[300.0, 200.0]]);
});

it('counts left to right within one line', function () {
    $driver = new FakeDriver([[
        scopedControl('Star', 120.0, 100.0),
        scopedControl('Star', 40.0, 100.0),
        scopedControl('Star', 80.0, 100.0),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->nth(3)->tap('Star');

    expect($driver->taps)->toBe([[120.0, 100.0]]);
});

it('says how many matched when nth() asks for one past the end', function () {
    $driver = new FakeDriver([[
        scopedControl('Delete', 300.0, 100.0),
        scopedControl('Delete', 300.0, 200.0),
    ]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: $this->failures))->nth(3)->tap('Delete'))
        ->toThrow(AssertionFailedError::class, 'Could not find [Delete] to tap. Found 2 [Delete], so there is no number 3.');

    expect($driver->taps)->toBe([]);
});

it('applies nth() to the next call only', function () {
    $driver = new FakeDriver([[
        scopedControl('Delete', 300.0, 100.0),
        scopedControl('Delete', 300.0, 200.0),
    ]]);
    $screen = new Screen($driver, timeoutSeconds: 0, failureDirectory: $this->failures);

    $screen->nth(1)->tap('Delete');

    expect(fn () => $screen->tap('Delete'))
        ->toThrow(AssertionFailedError::class, 'matches more than one Button. Pick one with nth()');
});

it('refuses nth() on a call that looks nothing up', function () {
    $driver = new FakeDriver([[scopedControl('Note', 40.0, 300.0)]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0))->nth(2)->scroll('down'))
        ->toThrow(SimulatorException::class, 'scroll() does not look one up');
});

it('refuses an nth() that does not count from 1', function () {
    expect(fn () => (new Screen(new FakeDriver([[]]), timeoutSeconds: 0))->nth(0))
        ->toThrow(SimulatorException::class, 'nth() counts from 1');
});

it('checks the state of the nth match', function () {
    $driver = new FakeDriver([[
        scopedControl('Notify', 300.0, 100.0, ['role' => 'Switch', 'checked' => false]),
        scopedControl('Notify', 300.0, 200.0, ['role' => 'Switch', 'checked' => true]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))
        ->nth(2)->assertChecked('Notify')
        ->nth(1)->assertNotChecked('Notify');

    expect(true)->toBeTrue();
});

it('taps a control inside the card within() names', function () {
    $driver = new FakeDriver([orderCards()]);

    (new Screen($driver, timeoutSeconds: 0))->within('Order 2', fn (Screen $card) => $card->tap('Pay'));

    expect($driver->taps)->toBe([[300.0, 320.0]]);
});

it('finds the container again on every read, after it moves', function () {
    $moved = array_map(function (array $element): array {
        $element['center'][1] -= 120.0;

        if (isset($element['frame'])) {
            $element['frame'][1] -= 120.0;
        }

        return $element;
    }, orderCards());
    $driver = new FakeDriver([orderCards(), $moved]);

    (new Screen($driver, timeoutSeconds: 0))->within('Order 2', function (Screen $card): void {
        $card->assertSee('Pay')->tap('Pay');
    });

    expect($driver->taps)->toBe([[300.0, 200.0]]);
});

it('sees only what is inside the container', function () {
    $elements = [
        scopedControl('Receipt', 195.0, 150.0, ['role' => 'Other', 'frame' => [0.0, 100.0, 390.0, 100.0]]),
        scopedControl('Paid', 100.0, 170.0, ['role' => 'StaticText']),
        scopedControl('Overdue', 100.0, 400.0, ['role' => 'StaticText']),
    ];
    $driver = new FakeDriver([$elements]);

    $screen = new Screen($driver, timeoutSeconds: 0, failureDirectory: $this->failures);
    $screen->within('Receipt', fn (Screen $receipt) => $receipt->assertSee('Paid')->assertDontSee('Overdue'));

    expect(fn () => $screen->within('Receipt', fn (Screen $receipt) => $receipt->assertSee('Overdue')))
        ->toThrow(AssertionFailedError::class, 'Looked only within [Receipt].');
});

it('says when the container itself is not on screen', function () {
    $driver = new FakeDriver([orderCards()]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: $this->failures))
        ->within('Order 3', fn (Screen $card) => $card->tap('Pay')))
        ->toThrow(AssertionFailedError::class, 'Could not find [Order 3] to look within.');
});

it('leaves the screen within() returns looking at everything', function () {
    $driver = new FakeDriver([orderCards()]);
    $screen = new Screen($driver, timeoutSeconds: 0, failureDirectory: $this->failures);

    $outer = $screen->within('Order 1', fn (Screen $card) => $card->tap('Pay'));

    expect($outer)->toBe($screen)
        ->and(fn () => $outer->tap('Pay'))->toThrow(AssertionFailedError::class, 'matches more than one Button');
});

it('counts with nth() inside a container', function () {
    $elements = [
        scopedControl('Basket', 195.0, 200.0, ['role' => 'Other', 'frame' => [0.0, 100.0, 390.0, 200.0]]),
        scopedControl('Remove', 300.0, 150.0),
        scopedControl('Remove', 300.0, 250.0),
        scopedControl('Remove', 300.0, 400.0),
    ];
    $driver = new FakeDriver([$elements]);

    (new Screen($driver, timeoutSeconds: 0))->within('Basket', fn (Screen $basket) => $basket->nth(2)->tap('Remove'));

    expect($driver->taps)->toBe([[300.0, 250.0]]);
});

it('refuses nth() left pending before within()', function () {
    expect(fn () => (new Screen(new FakeDriver([orderCards()]), timeoutSeconds: 0))
        ->nth(2)->within('Order 1', fn (Screen $card) => $card->tap('Pay')))
        ->toThrow(SimulatorException::class, 'Call it inside within()');
});

it('fails at once when the container matches more than one control', function () {
    $cards = array_map(fn (array $element): array => array_merge($element, ['label' => 'Order']), [orderCards()[0], orderCards()[2]]);
    $driver = new FakeDriver([[...$cards, scopedControl('Pay', 300.0, 170.0)]]);
    $started = microtime(true);

    expect(fn () => (new Screen($driver, timeoutSeconds: 5, failureDirectory: $this->failures))
        ->within('Order', fn (Screen $card) => $card->tap('Pay')))
        ->toThrow(AssertionFailedError::class, "within('Order') matches more than one control.");

    expect(microtime(true) - $started)->toBeLessThan(2.0);
});
