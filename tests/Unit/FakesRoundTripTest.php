<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\SerializableClosure\Serializers\Native;
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

    // Laravel 12 throws a StrayRequestException, 11 a RuntimeException.
    expect(fn () => AppLog::check($this->driver))->toThrow(AppError::class, 'Attempted request to [https://api.shipping.test/rates] without a matching fake.');
});

it('sends a fake the test adds after the first screen, and clears what the app recorded before it, as Laravel does', function () {
    Http::fake(['api.stripe.test/*' => Http::response(['status' => 'succeeded'])]);
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(fn () => Http::post('https://api.stripe.test/charges', ['amount' => 1]));

    Http::fake(['api.shipping.test/*' => Http::response(['express' => 1200])]);
    FakeSync::sync($this->driver);

    $express = $this->device->tap(fn () => Http::get('https://api.shipping.test/rates')->json('express'));

    expect($express)->toBe(1200)
        ->and(array_filter($this->driver->events, fn (array $event): bool => $event === ['pushFile', 'storage/'.Payload::FAKES]))->toHaveCount(2);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'charges'));
});

it('keeps what the app recorded when the test only stops stray requests', function () {
    Http::fake(['api.stripe.test/*' => Http::response(['status' => 'succeeded'])]);
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(fn () => Http::post('https://api.stripe.test/charges', ['amount' => 1]));

    Http::preventStrayRequests();
    FakeSync::sync($this->driver);
    $this->device->tap(fn () => null);

    Http::assertSentCount(1);
});

it('says a config value it cannot send to the app is not a plain value', function () {
    config(['features.mode' => new ArrayObject]);

    expect(fn () => FakeSync::sync($this->driver))
        ->toThrow(SimulatorException::class, "config('features.mode') holds a ArrayObject");
});

it('still runs a signed closure, with the class that runs closure code left out of the file', function () {
    $bytes = Payload::encode(['test' => 'x', 'http' => ['fakes' => [fn () => 'hi']]], 'key');
    $closure = Payload::unpack(Payload::decode($bytes)['http']['fakes'][0]);
    $bare = 'pest-simulator-fakes 1 key'."\n".serialize(['config' => new Native(fn () => 'ran')]);

    expect($closure())->toBe('hi')
        ->and(Payload::decode($bare)['config'])->toBeInstanceOf(__PHP_Incomplete_Class::class);
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
        ->and(array_keys($this->driver->appFiles))->toBe(['database/database.sqlite'])
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

it('keeps the test\'s own error when the device cannot take the fakes back, and the next test does', function () {
    Http::fake(['api.stripe.test/*' => Http::response(['status' => 'succeeded'])]);
    FakeSync::sync($this->driver);
    $this->driver->removeError = new SimulatorException('device offline');

    FakeSync::end($this->driver);

    expect($this->driver->appFiles)->toHaveKey('storage/'.Payload::FAKES);

    $this->driver->removeError = null;
    $this->refreshApplication();
    FakeSync::begin();
    FakeSync::sync($this->driver);

    expect($this->driver->appFiles)->not->toHaveKey('storage/'.Payload::FAKES);
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

it('goes on through a sequence when the test adds a fake, and pushes nothing for a closure that did not change', function () {
    Http::fake(['api.echo.test/*' => fn () => Http::response('echo')]);
    Http::fakeSequence('api.rates.test/*')->push(['rate' => 1])->push(['rate' => 2]);
    FakeSync::sync($this->driver);
    FakeSync::sync($this->driver);
    $this->device->boot();

    $first = $this->device->tap(fn () => Http::get('https://api.rates.test/a')->json('rate'));

    Http::preventStrayRequests();
    FakeSync::sync($this->driver);

    $second = $this->device->tap(fn () => Http::get('https://api.rates.test/b')->json('rate'));

    expect([$first, $second])->toBe([1, 2])
        ->and(array_filter($this->driver->events, fn (array $event): bool => $event === ['pushFile', 'storage/'.Payload::FAKES]))->toHaveCount(2);

    Http::assertSequencesAreEmpty();
});

it('says a sequence the app has not run through is not empty', function () {
    Http::fakeSequence('api.rates.test/*')->push(['rate' => 1])->push(['rate' => 2]);
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(fn () => Http::get('https://api.rates.test/a'));

    expect(fn () => Http::assertSequencesAreEmpty())->toThrow(AssertionFailedError::class, 'Not all response sequences are empty.');
});

it('sets a config value under an empty array, and empties one, as the test did', function () {
    config(['features.flags' => [], 'features.options' => ['key' => 'x'], 'features.gone' => 'here']);
    FakeSync::begin();

    config(['features.flags.beta' => true, 'features.options' => []]);
    $all = config('features');
    unset($all['gone']);
    config(['features' => $all]);
    FakeSync::sync($this->driver);
    $this->device->run(fn () => config(['features.gone' => 'app', 'features.options' => ['key' => 'app']]));
    $this->device->boot();

    [$flags, $options, $gone] = $this->device->tap(fn (): array => [config('features.flags'), config('features.options'), config()->has('features.gone')]);

    expect($flags)->toBe(['beta' => true])
        ->and($options)->toBe([])
        ->and($gone)->toBeFalse();

    FakeSync::end($this->driver);

    [$options, $gone] = $this->device->tap(fn (): array => [config('features.options'), config('features.gone')]);

    expect($options)->toBe(['key' => 'app'])
        ->and($gone)->toBe('app');
});

it('hands the test a GET request\'s query and a multipart request\'s parts', function () {
    Http::fake();
    FakeSync::sync($this->driver);
    $this->device->boot();

    $this->device->tap(function (): void {
        Http::get('https://api.shop.test/search', ['q' => 'shoes']);
        Http::asMultipart()->attach('receipt', 'PDF bytes', 'receipt.pdf')->post('https://api.shop.test/upload', ['order' => '2']);
    });

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.shop.test/search?q=shoes' && $request['q'] === 'shoes');
    Http::assertSent(fn (Request $request): bool => $request->hasFile('receipt', 'PDF bytes', 'receipt.pdf'));
});

it('leaves Laravel\'s own Http fake as it is outside a mobile() test', function () {
    Run::clear();
    FakeSync::end($this->driver);

    Http::fake();
    Http::get('https://example.test/x');
    Http::fake();

    Http::assertNothingSent();
});

it('freezes the same moment in the app in the hour a clock goes back', function () {
    $this->travelTo(Carbon::parse('2026-11-01 05:30:00', 'UTC')->setTimezone('America/New_York'));
    $expected = now()->getTimestamp();
    FakeSync::sync($this->driver);
    Carbon::setTestNow();
    $this->device->boot();

    expect($this->device->tap(fn (): int => now()->getTimestamp()))->toBe($expected);
});

it('takes away the fakes a run that stopped part way left on the app', function () {
    $this->driver->appFiles['storage/'.Payload::FAKES] = 'left over';
    $this->driver->appFiles['storage/'.Payload::RECORDS] = 'left over';

    FakeSync::sync($this->driver);

    expect($this->driver->appFiles)->toBe([]);
});

it('notices a push that kept the file\'s size within the same second', function () {
    config(['features.express' => true]);
    FakeSync::sync($this->driver);
    $this->device->boot();

    config(['features.express' => false]);
    FakeSync::sync($this->driver);

    expect($this->device->tap(fn () => config('features.express')))->toBeFalse();
});

it('reads no fakes file in an app that is not a debug build', function () {
    config(['features.express' => true]);
    FakeSync::sync($this->driver);
    $this->device->run(fn () => config(['app.debug' => false]));
    $this->device->boot();

    expect($this->device->tap(fn () => config('features.express')))->toBeNull();
});

it('reads no fakes file in an app in production', function () {
    config(['features.express' => true]);
    FakeSync::sync($this->driver);
    $this->device->run(function (Application $app): void {
        $app['env'] = 'production';
    });
    $this->device->boot();

    expect($this->device->tap(fn () => config('features.express')))->toBeNull();
});

it('unserializes no class the fakes file is not made of', function () {
    $bytes = 'pest-simulator-fakes 1 key'."\n".serialize(['test' => 'x', 'config' => new ArrayObject]);

    expect(Payload::decode($bytes)['config'])->toBeInstanceOf(__PHP_Incomplete_Class::class);
});

it('allows the stray requests the test let through', function () {
    Http::fake(['api.stripe.test/*' => Http::response('ok')]);
    Http::preventStrayRequests();
    Http::allowStrayRequests(['https://api.maps.test/*']);
    FakeSync::sync($this->driver);

    expect(FakeSync::payload()['http']['allow'] ?? null)->toBe(['https://api.maps.test/*']);
})->skip(! (new ReflectionClass(Factory::class))->hasProperty('allowedStrayRequestUrls'), 'Laravel 11 has no list of stray requests to allow.');
