<?php

declare(strict_types=1);

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Exceptions\SimulatorException;

afterEach(function () {
    Configuration::reset();
});

it('opens the custom scheme when a scheme is set', function () {
    Configuration::configure([
        'scheme' => 'myapp',
        'host' => 'example.net',
    ]);

    expect(Configuration::resolve()->urlFor('/lights'))->toBe('myapp://lights')
        ->and(Configuration::resolve()->urlFor('https://example.net/lights'))->toBe('https://example.net/lights');
});

it('opens the https host when the app has no custom scheme', function () {
    withNativeEnvironment(function () {
        Configuration::configure(['host' => 'example.net']);

        expect(Configuration::resolve()->urlFor('lights'))->toBe('https://example.net/lights');
    });
});

it('reads the deep link host from the environment', function () {
    withNativeEnvironment(function () {
        putenv('NATIVEPHP_DEEPLINK_HOST=example.net');
        putenv('NATIVEPHP_APP_ID=com.example.app');

        $configuration = Configuration::resolve();

        expect($configuration->urlFor('/lights'))->toBe('https://example.net/lights')
            ->and($configuration->deeplinkHost())->toBe('example.net')
            ->and($configuration->appId())->toBe('com.example.app');
    });
});

it('asks for a scheme or host before opening a screen', function () {
    withNativeEnvironment(function () {
        Configuration::resolve()->urlFor('/lights');
    });
})->throws(SimulatorException::class);

function withNativeEnvironment(Closure $callback): void
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
