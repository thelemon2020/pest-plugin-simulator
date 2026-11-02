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
        $mirror = Mirror::current();

        if ($mirror === null) {
            return;
        }

        $mirror->sync();

        // From 4.7, NativePHP calls tree() only for a frame it publishes, and it skips a
        // frame that matches the last one. A handler that changes nothing on screen, like
        // one that only dispatches a job, would never have its fakes recorded. Moving the
        // epoch makes the frame after this event go out whatever it holds.
        if (function_exists('nativephp_force_full_frame')) {
            nativephp_force_full_frame();
        }
    }

    public function nav(array $payload): void {}
}
