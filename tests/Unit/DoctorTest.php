<?php

declare(strict_types=1);

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Doctor;
use Tests\Support\FakeMachine;

afterEach(function () {
    Configuration::reset();
});

it('passes when a platform and the app id are configured', function () {
    Configuration::configure([
        'scheme' => 'myapp',
        'host' => 'example.net',
        'bundle_id' => 'com.example.app',
    ]);

    $result = (new Doctor(new FakeMachine))->run(Configuration::resolve());

    expect($result->successful())->toBeTrue()
        ->and($result->render())->toContain('myapp')
        ->and($result->render())->toContain('example.net')
        ->and($result->render())->toContain('com.example.app')
        ->and($result->render())->toContain('iOS Simulator tests can run on this machine.')
        ->and($result->render())->toContain('Android Emulator tests can run on this machine.');
});

it('accepts a host when the custom scheme is unset', function () {
    isolateNativeConfig(function () {
        Configuration::configure([
            'host' => 'example.net',
            'bundle_id' => 'com.example.app',
        ]);

        $result = (new Doctor(new FakeMachine))->run(Configuration::resolve());

        expect($result->successful())->toBeTrue()
            ->and($result->render())->toContain('unset');
    });
});

it('fails when the app id is missing', function () {
    isolateNativeConfig(function () {
        Configuration::configure(['scheme' => 'myapp']);

        $result = (new Doctor(new FakeMachine))->run(Configuration::resolve());

        expect($result->successful())->toBeFalse()
            ->and($result->render())->toContain('Set NATIVEPHP_APP_ID.');
    });
});

it('still passes when only android tools are installed', function () {
    Configuration::configure([
        'scheme' => 'myapp',
        'bundle_id' => 'com.example.app',
    ]);

    $result = (new Doctor(new FakeMachine(xcrun: null, companion: null)))->run(Configuration::resolve());

    expect($result->successful())->toBeTrue()
        ->and($result->render())->toContain('unavailable')
        ->and($result->render())->toContain('Android Emulator tests can run on this machine.');
});

it('names the platforms this machine can run', function () {
    expect((new Doctor(new FakeMachine(xcrun: null, companion: null)))->platforms())->toBe(['android'])
        ->and((new Doctor(new FakeMachine(sdk: null, adb: null, emulator: null)))->platforms())->toBe(['ios']);
});

it('fails when neither platform can run', function () {
    Configuration::configure([
        'scheme' => 'myapp',
        'bundle_id' => 'com.example.app',
    ]);

    $result = (new Doctor(new FakeMachine(
        xcrun: null,
        companion: null,
        sdk: null,
        adb: null,
        emulator: null,
    )))->run(Configuration::resolve());

    expect($result->successful())->toBeFalse()
        ->and($result->render())->toContain('missing');
});

function isolateNativeConfig(Closure $callback): void
{
    $keys = ['NATIVEPHP_DEEPLINK_SCHEME', 'NATIVEPHP_DEEPLINK_HOST', 'NATIVEPHP_APP_ID'];
    $previous = [];

    foreach ($keys as $key) {
        $value = getenv($key);
        $previous[$key] = $value === false ? null : $value;
        putenv($key);
    }

    Configuration::reset();

    try {
        $callback();
    } finally {
        foreach ($previous as $key => $value) {
            putenv($value === null ? $key : $key.'='.$value);
        }

        Configuration::reset();
    }
}
