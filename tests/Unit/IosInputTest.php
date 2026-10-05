<?php

declare(strict_types=1);

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
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

    expect($client->calls[0])->toBe(['stream', 'hid', Hid::swipe(10, 20, 30, 40, 0.15)])
        ->and($client->calls[0][2][0])->toContain("\x31".pack('e', 0.15))
        ->and($client->calls[1])->toBe(['streamPaced', 'hid', Hid::tap(50, 60), 800_000]);
});

it('encodes a slower swipe than the default', function () {
    expect(Hid::swipe(1, 2, 3, 4, 0.6)[0])->toContain("\x31".pack('e', 0.6))
        ->and(Hid::swipe(1, 2, 3, 4)[0])->toContain("\x31".pack('e', 0.3))
        ->and(Hid::swipe(1, 2, 3, 4, 0.6)[0])->not->toBe(Hid::swipe(1, 2, 3, 4)[0]);
});

function iosInputDriver(RecordingClient $client): IosDriver
{
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve());
    (new ReflectionProperty(IosDriver::class, 'client'))->setValue($driver, $client);

    return $driver;
}
