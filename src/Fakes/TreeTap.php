<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Native\Mobile\Edge\Contracts\TreeObserver;

/**
 * Where Mirror hooks into a running NativePHP screen. NativePHP calls event() before it
 * handles each tap, back press, or deep link, and tree() after each render.
 *
 * Only loaded inside an app that has NativePHP, which is the only place the interface it
 * implements exists.
 */
final class TreeTap implements TreeObserver
{
    public function tree(array $tree, string $uri): void
    {
        Mirror::current()?->record();
    }

    public function event(array $event, ?string $label): void
    {
        Mirror::current()?->sync();
    }

    public function nav(array $payload): void {}
}
