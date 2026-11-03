<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Native\Mobile\Edge\NativeComponent;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Fakes\FakeSync;
use NativePhp\Simulator\Fakes\Mirror;
use NativePhp\Simulator\Fakes\TreeTap;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Sessions;
use Tests\Support\DeviceApp;
use Tests\Support\FakeDriver;
use Tests\Support\LaravelTestCase;

// NativePHP is not a dependency of the plugin, so its classes come from the stub.
require_once __DIR__.'/../Support/nativephp.stub';

uses(LaravelTestCase::class);

beforeEach(function () {
    $this->driver = new FakeDriver([[['label' => 'Pay', 'role' => 'Button', 'id' => null, 'center' => [10, 10]]]]);
    Configuration::configure(['scheme' => 'myapp', 'timeout' => 0]);
    Run::useDevice(new Device('ios', 'iPhone 17', true));
    Sessions::fake($this->driver);
    FakeSync::begin();
    $this->device = new DeviceApp($this->driver);

    // The screen's runloop, which has just published a frame.
    $this->screen = new NativeComponent;
    $this->previous = NativeComponent::markActive($this->screen);
    $this->published = new ReflectionProperty(NativeComponent::class, 'publishedFrame');
    $this->published->setValue($this->screen, 'frame-1');
});

afterEach(function () {
    NativeComponent::restoreActive($this->previous);
    FakeSync::end($this->driver);
    Sessions::fake(null);
    Run::clear();
    Configuration::reset();
    $this->device->remove();
});

it('lets the frame after an event go out while a test\'s fakes are in place', function () {
    Bus::fake();
    FakeSync::sync($this->driver);
    $this->device->boot();

    $this->device->run(fn () => (new TreeTap)->event(['type' => 1], 'ship'));

    expect($this->published->getValue($this->screen))->toBeNull();
});

it('records the fakes after a frame, and lets the next frame go out too', function () {
    Bus::fake();
    FakeSync::sync($this->driver);
    $this->device->boot();

    // A poll's handler runs with no event before it: only the frame before it can let
    // the frame after it go out.
    $this->device->run(function (): void {
        (new TreeTap)->tree([], '/checkout');
        Bus::dispatch(new stdClass);
        $this->published->setValue($this->screen, 'frame-1');
        (new TreeTap)->tree([], '/checkout');
    });

    expect($this->published->getValue($this->screen))->toBeNull();
    Bus::assertDispatched(stdClass::class);
});

it('leaves the frame alone when no test\'s fakes are in place', function () {
    $this->device->boot();

    $this->device->run(function (): void {
        (new TreeTap)->event(['type' => 1], 'ship');
        (new TreeTap)->tree([], '/checkout');
    });

    expect(Mirror::current())->not->toBeNull()
        ->and(Mirror::current()?->testing())->toBeFalse()
        ->and($this->published->getValue($this->screen))->toBe('frame-1');
});

it('does nothing in an app the plugin does not mirror into', function () {
    Mirror::reset();

    (new TreeTap)->event(['type' => 1], 'ship');
    (new TreeTap)->tree([], '/checkout');

    expect($this->published->getValue($this->screen))->toBe('frame-1');
});
