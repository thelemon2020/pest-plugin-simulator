<?php

declare(strict_types=1);

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Grpc\Client;
use NativePhp\Simulator\Hid;
use NativePhp\Simulator\IosDriver;
use Tests\Support\RecordingClient;

afterEach(function () {
    Configuration::reset();
});

it('swipes for a given duration and holds a press', function () {
    $client = new RecordingClient;
    $driver = iosInputDriver($client);

    $driver->swipe(10, 20, 30, 40, 0.15);
    $driver->press(50, 60, 0.8);

    expect($client->calls[0])->toBe(['stream', 'hid', Hid::swipe(10, 20, 30, 40, 0.15), 0.15])
        ->and($client->calls[0][2][0])->toContain("\x31".pack('e', 0.15))
        ->and($client->calls[1])->toBe(['streamPaced', 'hid', Hid::tap(50, 60), 800_000]);
});

it('encodes a slower swipe than the default', function () {
    expect(Hid::swipe(1, 2, 3, 4, 0.6)[0])->toContain("\x31".pack('e', 0.6))
        ->and(Hid::swipe(1, 2, 3, 4)[0])->toContain("\x31".pack('e', 0.3))
        ->and(Hid::swipe(1, 2, 3, 4, 0.6)[0])->not->toBe(Hid::swipe(1, 2, 3, 4)[0]);
});

it('lifts the touch when a tap fails between its down and up', function () {
    $client = new class extends Client
    {
        /** @var list<list<string>> */
        public array $streamed = [];

        public function __construct()
        {
            parent::__construct('http://127.0.0.1:0');
        }

        public function stream(string $method, array $messages, float $durationSeconds = 0.0): string
        {
            $this->streamed[] = $messages;

            return '';
        }

        public function streamPaced(string $method, array $messages, int $gapMicroseconds): string
        {
            throw new SimulatorException('Companion [hid] did not answer in time');
        }
    };
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve());
    (new ReflectionProperty(IosDriver::class, 'client'))->setValue($driver, $client);

    expect(fn () => $driver->tap(50, 60))->toThrow(SimulatorException::class, 'did not answer in time')
        ->and($client->streamed)->toBe([[Hid::tap(50, 60)[1]]]);
});

it('types each key as a stroke of its own', function () {
    $client = new RecordingClient;
    $driver = iosInputDriver($client);

    $driver->text('aB');

    expect($client->calls)->toBe([['streamStrokes', 'hid', Hid::keystrokes('aB'), 20_000]]);
});

it('allows a back gesture its own duration on top of the call timeout', function () {
    $client = new RecordingClient;
    $driver = iosInputDriver($client);

    $driver->back();

    expect($client->calls[0][3] ?? null)->toBe(0.6);
});

it('gives a companion call as long as a screen step, within bounds', function (float $step, float $call) {
    Configuration::configure(['timeout' => $step]);
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve());

    $client = (new ReflectionMethod(IosDriver::class, 'connect'))->invoke($driver, 10882);

    expect((new ReflectionProperty(Client::class, 'timeoutSeconds'))->getValue($client))->toBe($call);
})->with([
    'the configured step' => [15.0, 15.0],
    'no less than a slow read needs' => [2.0, 10.0],
    'no more than the client allows' => [45.0, 30.0],
]);

function iosInputDriver(RecordingClient $client): IosDriver
{
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve());
    (new ReflectionProperty(IosDriver::class, 'client'))->setValue($driver, $client);

    return $driver;
}
