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

it('types a key at a time, each once the field shows the last and the app has answered', function () {
    $field = fn (string $value): array => ['label' => 'Echo', 'role' => 'TextField', 'id' => null, 'center' => [200.0, 175.0], 'value' => $value];
    $driver = new FakeDriver([
        [$field(''), marker(0)],
        [$field('h'), marker(0)],
        [$field('he'), marker(0)],
        [$field('hey'), marker(0)],
    ]);

    (new Screen($driver, timeoutSeconds: 1))->type('Echo', 'hey');

    expect($driver->texts)->toBe(['h', 'e', 'y']);
});

it('types in one go for an app that shows no marker', function () {
    $driver = new FakeDriver([[['label' => 'Echo', 'role' => 'TextField', 'id' => null, 'center' => [200.0, 175.0], 'value' => 'hey']]]);

    (new Screen($driver, timeoutSeconds: 1))->type('Echo', 'hey');

    expect($driver->texts)->toBe(['hey']);
});
