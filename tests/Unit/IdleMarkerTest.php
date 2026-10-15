<?php

declare(strict_types=1);

use NativePhp\Simulator\IdleMarker;
use NativePhp\Simulator\Screen;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeDriver;

beforeEach(function () {
    $this->failures = sys_get_temp_dir().'/simulator-idle-'.uniqid('', true);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->failures));
});

function marker(int $pending): array
{
    return ['label' => (string) $pending, 'role' => 'Other', 'id' => IdleMarker::ID, 'center' => [0.5, 0.5]];
}

function idleText(string $label, array $extra = []): array
{
    return array_merge(['label' => $label, 'role' => 'StaticText', 'id' => null, 'center' => [100.0, 200.0]], $extra);
}

it('takes the marker out of a read and keeps its count', function () {
    [$elements, $pending] = IdleMarker::split([idleText('Saved'), marker(3)]);

    expect($elements)->toBe([idleText('Saved')])
        ->and($pending)->toBe(3)
        ->and(IdleMarker::split([idleText('Saved')])[1])->toBeNull();
});

it('does not take a read the app is still answering as the answer', function () {
    $driver = new FakeDriver([
        [idleText('Draft'), marker(1)],
        [idleText('Saved'), marker(0)],
    ]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0.5, failureDirectory: $this->failures))->assertSee('Draft'))
        ->toThrow(AssertionFailedError::class, 'Did not see [Draft].');
});

it('passes once the app has answered', function () {
    $driver = new FakeDriver([
        [idleText('Saving'), marker(2)],
        [idleText('Saved'), marker(0)],
    ]);

    (new Screen($driver, timeoutSeconds: 1))->assertSee('Saved');

    expect(true)->toBeTrue();
});

it('says the app was still busy when it never answered', function () {
    $driver = new FakeDriver([[idleText('Saving'), marker(2)]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0.3, failureDirectory: $this->failures))->assertSee('Saved'))
        ->toThrow(AssertionFailedError::class, 'The app was still busy: PHP had not taken 2 events it was sent.');
});

it('leaves the marker out of what a failure lists', function () {
    $driver = new FakeDriver([[idleText('Saving'), marker(0)]]);

    try {
        (new Screen($driver, timeoutSeconds: 0, failureDirectory: $this->failures))->assertSee('Saved');
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())->not->toContain(IdleMarker::ID);
    }
});

it('types a key at a time for an app that shows the marker', function () {
    $field = fn (string $value): array => ['label' => 'Echo', 'role' => 'TextField', 'id' => null, 'center' => [200.0, 175.0], 'value' => $value];
    $driver = new FakeDriver([]);
    $driver->reading = fn (): array => [$field(implode('', $driver->texts)), marker(0)];

    (new Screen($driver, timeoutSeconds: 1))->type('Echo', 'hey');

    expect($driver->texts)->toBe(['h', 'e', 'y']);
});

it('types each key once PHP has taken every event before it, the erase included', function () {
    $field = fn (string $value): array => ['label' => 'Echo', 'role' => 'TextField', 'id' => null, 'center' => [200.0, 175.0], 'value' => $value];
    $driver = new FakeDriver([]);
    $taken = 0;
    $reads = 0;
    $last = ['texts' => 0, 'pending' => 0, 'value' => 'old'];
    $typedAt = [];

    // PHP takes an event every other read. The erase is one, and so is each key.
    $driver->reading = function () use ($driver, $field, &$taken, &$reads, &$last, &$typedAt): array {
        $posted = $driver->clears + count($driver->texts);
        $reads++;

        if ($taken < $posted && $reads % 2 === 0) {
            $taken++;
        }

        for ($key = $last['texts']; $key < count($driver->texts); $key++) {
            $typedAt[] = [$last['value'], $last['pending']];
        }

        $value = $driver->clears > 0 ? implode('', $driver->texts) : 'old';
        $last = ['texts' => count($driver->texts), 'pending' => $posted - $taken, 'value' => $value];

        return [$field($value), marker($posted - $taken)];
    };

    (new Screen($driver, timeoutSeconds: 2))->type('Echo', 'hey');

    expect($driver->texts)->toBe(['h', 'e', 'y'])
        ->and($typedAt)->toBe([['', 0], ['h', 0], ['he', 0]]);
});

it('does not wait out a space that a read trims off the field', function () {
    $field = fn (string $value): array => ['label' => 'Name', 'role' => 'TextField', 'id' => null, 'center' => [200.0, 175.0], 'value' => $value];
    $driver = new FakeDriver([]);
    $driver->reading = fn (): array => [$field(trim(implode('', $driver->texts))), marker(0)];
    $started = microtime(true);

    (new Screen($driver, timeoutSeconds: 10))->type('Name', 'Ada L');

    expect($driver->texts)->toBe(['A', 'd', 'a', ' ', 'L'])
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
});

it('types in one burst first where a late answer only loses keys', function () {
    $field = fn (string $value): array => ['label' => 'Echo', 'role' => 'TextField', 'id' => null, 'center' => [200.0, 175.0], 'value' => $value];
    $driver = new FakeDriver([[$field('hey'), marker(0)]]);
    $driver->resends = false;

    (new Screen($driver, timeoutSeconds: 1))->type('Echo', 'hey');

    expect($driver->texts)->toBe(['hey']);
});

it('retries a key at a time where a late answer only loses keys, once the burst lost some', function () {
    $field = fn (string $value): array => ['label' => 'Echo', 'role' => 'TextField', 'id' => null, 'center' => [200.0, 175.0], 'value' => $value];
    $driver = new FakeDriver([]);
    $driver->resends = false;
    $driver->reading = fn (): array => [$field(count($driver->texts) === 1 ? 'h' : implode('', array_slice($driver->texts, 1))), marker(0)];

    (new Screen($driver, timeoutSeconds: 0.5))->type('Echo', 'hey');

    expect($driver->texts)->toBe(['hey', 'h', 'e', 'y']);
});

it('types in one go for an app that shows no marker', function () {
    $driver = new FakeDriver([[['label' => 'Echo', 'role' => 'TextField', 'id' => null, 'center' => [200.0, 175.0], 'value' => 'hey']]]);

    (new Screen($driver, timeoutSeconds: 1))->type('Echo', 'hey');

    expect($driver->texts)->toBe(['hey']);
});
