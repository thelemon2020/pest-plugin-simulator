<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NativePhp\Simulator\AppLog;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Exceptions\AppError;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Fakes\FakeSync;
use NativePhp\Simulator\Fakes\Payload;
use NativePhp\Simulator\Fakes\RemoteHttpFactory;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\TestDatabase;
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
    AppLog::begin();
    Sessions::fake(null);
    Run::clear();
    Configuration::reset();
    Carbon::setTestNow();
    $this->device->remove();
});

it('binds an Http client whose fakes reach the app, which is Laravel\'s own otherwise', function () {
    expect(app(Factory::class))->toBeInstanceOf(RemoteHttpFactory::class)
        ->and(Http::getFacadeRoot())->toBe(app(Factory::class));

    Http::fake(['example.test/*' => Http::response('hi')]);

    expect(Http::get('https://example.test/a')->body())->toBe('hi');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.test/a');
});

it('gives the app the test\'s Http fakes, time, and config, and asserts on what the app sent', function () {
    Http::fake(['api.stripe.test/*' => Http::response(['status' => 'succeeded'])]);
    Http::preventStrayRequests();
    $this->travelTo('2026-12-24 09:00');
    config(['features.express' => true]);

    FakeSync::sync($this->driver);

    // The app is another process, with a clock of its own.
    Carbon::setTestNow();
    $this->device->boot();

    [$status, $now, $express] = $this->device->tap(fn (): array => [
        Http::post('https://api.stripe.test/charges', ['amount' => 4200])->json('status'),
        now()->toDateTimeString(),
        config('features.express'),
    ]);

    expect($status)->toBe('succeeded')
        ->and($now)->toBe('2026-12-24 09:00:00')
        ->and($express)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.test/charges' && $request['amount'] === 4200);
    Http::assertSentCount(1);

    expect(fn () => Http::assertSent(fn (Request $request): bool => $request['amount'] === 1))->toThrow(AssertionFailedError::class)
        ->and(fn () => Http::assertNothingSent())->toThrow(AssertionFailedError::class);
});

it('fails the test on a stray request the app made, as an app error', function () {
    Http::fake(['api.stripe.test/*' => Http::response(['status' => 'succeeded'])]);
    Http::preventStrayRequests();
    FakeSync::sync($this->driver);
    AppLog::start($this->driver);
    $this->device->boot();

    $this->device->tap(function (): void {
        try {
            Http::get('https://api.shipping.test/rates');
        } catch (Throwable $exception) {
            report($exception);
        }
    });

    expect(fn () => AppLog::check($this->driver))->toThrow(AppError::class, 'The app logged Illuminate\Http\Client\StrayRequestException: Attempted request to [https://api.shipping.test/rates] without a matching fake.');
});

it('sends a fake the test adds after the first screen, and keeps what the app recorded before it', function () {
    Http::fake(['api.stripe.test/*' => Http::response(['status' => 'succeeded'])]);
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(fn () => Http::post('https://api.stripe.test/charges', ['amount' => 1]));

    Http::fake(['api.shipping.test/*' => Http::response(['express' => 1200])]);
    FakeSync::sync($this->driver);

    $express = $this->device->tap(fn () => Http::get('https://api.shipping.test/rates')->json('express'));

    expect($express)->toBe(1200)
        ->and(array_filter($this->driver->events, fn (array $event): bool => $event === ['pushFile', 'storage/'.Payload::FAKES]))->toHaveCount(2);

    Http::assertSentCount(2);
});

it('pushes the fakes file only when what the test faked has changed', function () {
    Http::fake(['api.stripe.test/*' => Http::response(['status' => 'succeeded'])]);

    FakeSync::sync($this->driver);
    FakeSync::sync($this->driver);

    expect(array_filter($this->driver->events, fn (array $event): bool => $event === ['pushFile', 'storage/'.Payload::FAKES]))->toHaveCount(1);
});

it('runs the test\'s closures and sequences in the app', function () {
    Http::fake([
        'api.echo.test/*' => fn (Request $request) => Http::response(['echo' => $request['word']]),
        'api.static.test/*' => static fn () => Http::response('static'),
    ]);
    Http::fakeSequence('api.rates.test/*')->push(['rate' => 1])->push(['rate' => 2])->whenEmpty(Http::response(['rate' => 0]));
    FakeSync::sync($this->driver);
    $this->device->boot();

    $answers = $this->device->tap(fn (): array => [
        Http::post('https://api.echo.test/words', ['word' => 'hello'])->json('echo'),
        Http::get('https://api.static.test/text')->body(),
        Http::get('https://api.rates.test/a')->json('rate'),
        Http::get('https://api.rates.test/b')->json('rate'),
        Http::get('https://api.rates.test/c')->json('rate'),
    ]);

    expect($answers)->toBe(['hello', 'static', 1, 2, 0]);
});

it('takes the fakes back off the app when the test ends', function () {
    $this->device->run(function (): void {
        Http::fake(['*' => Http::response('real stand-in')]);
    });
    $real = $this->device->run(fn () => app(Factory::class));

    Http::fake(['api.stripe.test/*' => Http::response(['status' => 'succeeded'])]);
    $this->travelTo('2026-12-24 09:00');
    config(['features.express' => true]);
    FakeSync::sync($this->driver);
    Carbon::setTestNow();
    $this->device->boot();

    expect($this->device->run(fn () => app(Factory::class)))->not->toBe($real);

    FakeSync::end($this->driver);

    [$client, $now, $express] = $this->device->tap(fn (): array => [app(Factory::class), Carbon::hasTestNow(), config('features.express')]);

    expect($client)->toBe($real)
        ->and($now)->toBeFalse()
        ->and($express)->toBeNull()
        ->and($this->driver->appFiles)->not->toHaveKey('storage/'.Payload::FAKES)
        ->and($this->driver->appFiles)->not->toHaveKey('storage/'.Payload::RECORDS);
});

it('applies nothing while the app only boots, as it does for config:cache', function () {
    config(['features.express' => true]);
    FakeSync::sync($this->driver);

    $this->device->bootWithoutRoute();

    expect($this->device->run(fn () => config('features.express')))->toBeNull();

    $this->device->boot();

    expect($this->device->run(fn () => config('features.express')))->toBeTrue();
});

it('leaves an app without the fakes file as it is', function () {
    $real = $this->device->run(fn () => app(Factory::class));
    $this->device->boot();
    $this->device->tap(fn () => null);

    expect($this->device->run(fn () => app(Factory::class)))->toBe($real)
        ->and($this->driver->appFiles)->toBe([])
        ->and(is_dir($this->device->root.'/storage/framework/pest-simulator'))->toBeFalse();
});

it('reads no record left by another test', function () {
    Http::fake();
    $this->driver->appFiles['storage/'.Payload::RECORDS] = serialize(['test' => 'another', 'http' => [[
        'request' => ['method' => 'GET', 'url' => 'https://api.stripe.test', 'headers' => [], 'body' => ''],
        'response' => null,
    ]]]);

    Http::assertNothingSent();
});

it('sends nothing for a test that fakes nothing', function () {
    FakeSync::sync($this->driver);

    expect(FakeSync::payload())->toBeNull()
        ->and($this->driver->appFiles)->toBe([]);
});

it('says a closure that uses $this cannot run in the app', function () {
    $this->word = 'hello';
    Http::fake(['api.echo.test/*' => function () {
        return Http::response($this->word);
    }]);

    expect(fn () => FakeSync::sync($this->driver))
        ->toThrow(SimulatorException::class, 'A closure given to a fake runs in the app, so it cannot use $this.');
});

it('pushes the fakes set before the first screen while the app is stopped for the database copy', function () {
    $database = (string) tempnam(sys_get_temp_dir(), 'simulator-');
    TestDatabase::fake(fn (): string => $database);
    TestDatabase::begin();
    Http::fake(['api.stripe.test/*' => Http::response(['status' => 'succeeded'])]);

    try {
        screen('/checkout')->tap('Pay');
    } finally {
        TestDatabase::fake(null);
        TestDatabase::end();
    }

    $order = array_map(fn (array $event): string => $event[0], array_values(array_filter(
        $this->driver->events,
        fn (array $event): bool => in_array($event[0], ['install', 'pushFile', 'open'], true),
    )));

    expect($order)->toBe(['install', 'pushFile', 'open']);
});
