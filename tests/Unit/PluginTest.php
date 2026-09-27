<?php

declare(strict_types=1);

use NativePhp\Simulator\Plugin;
use NativePhp\Simulator\Shutdown;
use Pest\Contracts\Plugins\Bootable;
use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Contracts\Plugins\Terminable;
use Pest\Plugin\Loader;

afterEach(function () {
    Shutdown::reset();
});

it('is booted by pest', function () {
    $plugins = array_map(
        fn (object $plugin): string => $plugin::class,
        Loader::getPlugins(Bootable::class),
    );

    expect($plugins)->toContain(Plugin::class)
        ->and(new Plugin)->toBeInstanceOf(HandlesArguments::class)
        ->and(new Plugin)->toBeInstanceOf(Terminable::class);
});

it('is declared for pest and for laravel package discovery', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer['extra']['pest']['plugins'])->toContain(Plugin::class)
        ->and($composer['extra']['laravel']['providers'])->toContain('NativePhp\\Simulator\\Laravel\\ServiceProvider');

    $cached = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/vendor/pest-plugins.json'), true);

    expect($cached)->toContain(Plugin::class);
});

it('runs shutdown tasks in reverse and then clears them', function () {
    $order = [];

    Shutdown::defer(function () use (&$order): void {
        $order[] = 'first';
    });
    Shutdown::defer(function () use (&$order): void {
        $order[] = 'second';
    });

    (new Plugin)->terminate();

    expect($order)->toBe(['second', 'first']);

    (new Plugin)->terminate();

    expect($order)->toBe(['second', 'first']);
});
