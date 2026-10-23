<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Fakes\FakeSync;
use NativePhp\Simulator\Fakes\RemoteFilesystemAdapter;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Sessions;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\DeviceApp;
use Tests\Support\FakeDriver;
use Tests\Support\LaravelTestCase;

uses(LaravelTestCase::class);

beforeEach(function () {
    $this->driver = new FakeDriver([[['label' => 'Pay', 'role' => 'Button', 'id' => null, 'center' => [10, 10]]]]);
    Configuration::configure(['scheme' => 'myapp', 'timeout' => 0]);
    Run::useDevice(new Device('ios', 'iPhone 17', true));
    Sessions::fake($this->driver);
    FakeSync::begin();
    $this->device = new DeviceApp($this->driver);
});

afterEach(function () {
    FakeSync::end($this->driver);
    Sessions::fake(null);
    Run::clear();
    Configuration::reset();
    $this->device->remove();
});

it('asserts on the files the app wrote to a disk the test faked', function () {
    Storage::fake('photos');
    FakeSync::sync($this->driver);
    $this->device->boot();

    $root = $this->device->tap(function (): string {
        Storage::disk('photos')->put('avatars/ada.jpg', 'jpeg bytes');

        return Storage::disk('photos')->path('');
    });

    expect($root)->toStartWith($this->device->root.'/storage/framework/testing/disks/photos')
        ->and(Storage::disk('photos'))->toBeInstanceOf(RemoteFilesystemAdapter::class);

    Storage::disk('photos')->assertExists('avatars/ada.jpg');
    Storage::disk('photos')->assertMissing('avatars/grace.jpg');

    expect(Storage::disk('photos')->get('avatars/ada.jpg'))->toBe('jpeg bytes')
        ->and(Storage::disk('photos')->allFiles())->toBe(['avatars/ada.jpg'])
        ->and(Storage::disk('photos')->directories())->toBe(['avatars'])
        ->and(fn () => Storage::disk('photos')->assertExists('avatars/grace.jpg'))->toThrow(AssertionFailedError::class)
        ->and(fn () => Storage::disk('photos')->assertDirectoryEmpty('avatars'))->toThrow(AssertionFailedError::class);
});

it('fakes the app\'s disk with the config the test gave Storage::fake()', function () {
    Storage::fake('photos', ['url' => 'https://cdn.example.test/photos', 'visibility' => 'public']);
    FakeSync::sync($this->driver);
    $this->device->boot();

    [$url, $root] = $this->device->tap(fn (): array => [Storage::disk('photos')->url('a.jpg'), Storage::disk('photos')->path('')]);

    expect($url)->toBe('https://cdn.example.test/photos/a.jpg')
        ->and($root)->toStartWith($this->device->root.'/storage/framework/testing/disks/photos');
});

it('pulls a file again only when the app changed it', function () {
    Storage::fake('photos');
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(fn () => Storage::disk('photos')->put('a.txt', 'one'));

    Storage::disk('photos')->assertExists('a.txt');
    Storage::disk('photos')->assertExists('a.txt');

    $pulls = fn (): int => count(array_filter($this->driver->events, fn (array $event): bool => $event === ['pullFile', 'storage/framework/testing/disks/photos/a.txt']));

    expect($pulls())->toBe(1);

    $this->device->tap(fn () => Storage::disk('photos')->put('a.txt', 'two'));

    expect(Storage::disk('photos')->get('a.txt'))->toBe('two')
        ->and($pulls())->toBe(2);
});

it('sees a file the app deleted go', function () {
    Storage::fake('photos');
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(fn () => Storage::disk('photos')->put('a.txt', 'one'));

    Storage::disk('photos')->assertExists('a.txt');

    $this->device->tap(fn () => Storage::disk('photos')->delete('a.txt'));

    Storage::disk('photos')->assertMissing('a.txt');
});

it('keeps a file the test wrote itself beside the app\'s', function () {
    Storage::fake('photos');
    FakeSync::sync($this->driver);
    $this->device->boot();
    Storage::disk('photos')->put('mine.txt', 'test');
    $this->device->tap(fn () => Storage::disk('photos')->put('app.txt', 'app'));

    Storage::disk('photos')->assertExists(['mine.txt', 'app.txt']);
    Storage::disk('photos')->assertCount('/', 2);
});

it('leaves a disk the test did not fake real in the app', function () {
    Storage::fake('photos');
    FakeSync::sync($this->driver);
    $this->device->boot();

    $local = $this->device->tap(fn (): string => Storage::disk('local')->path(''));

    expect($local)->not->toContain('framework/testing/disks');
});

it('forgets what the app wrote before the test faked the disk again', function () {
    Storage::fake('photos');
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(fn () => Storage::disk('photos')->put('before.txt', 'one'));
    Storage::disk('photos')->assertExists('before.txt');

    Storage::fake('photos');
    FakeSync::sync($this->driver);
    $this->device->tap(fn () => Storage::disk('photos')->put('after.txt', 'two'));

    Storage::disk('photos')->assertExists('after.txt');
    Storage::disk('photos')->assertMissing('before.txt');
    expect($this->device->run(fn (): bool => Storage::disk('photos')->exists('before.txt')))->toBeFalse();
});

it('puts the app\'s real disk back when the test ends', function () {
    $real = $this->device->run(fn (): string => Storage::disk('local')->path(''));

    Storage::fake('local');
    FakeSync::sync($this->driver);
    $this->device->boot();

    expect($this->device->tap(fn (): string => Storage::disk('local')->path('')))->not->toBe($real);

    FakeSync::end($this->driver);

    expect($this->device->tap(fn (): string => Storage::disk('local')->path('')))->toBe($real);
});
