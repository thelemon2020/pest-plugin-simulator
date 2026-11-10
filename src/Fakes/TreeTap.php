<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Native\Mobile\Edge\Contracts\TreeObserver;
use Native\Mobile\Edge\NativeComponent;
use ReflectionProperty;

/**
 * Where Mirror hooks into a running NativePHP screen. NativePHP calls event() before it
 * handles each tap, back press, or deep link, and tree() after each render.
 *
 * Only loaded inside an app that has NativePHP, which is the only place the interface it
 * implements exists.
 *
 * @internal
 */
final class TreeTap implements TreeObserver
{
    private static ?ReflectionProperty $publishedFrame = null;

    public function tree(array $tree, string $uri): void
    {
        $mirror = Mirror::current();

        if ($mirror === null) {
            return;
        }

        $mirror->record();
        self::republish($mirror);
    }

    public function event(array $event, ?string $label): void
    {
        $mirror = Mirror::current();

        if ($mirror === null) {
            return;
        }

        $mirror->sync();
        self::republish($mirror);
    }

    public function nav(array $payload): void {}

    /**
     * From 4.7, NativePHP holds back a frame that matches the last one it published, and
     * calls tree() only for a frame it publishes. A tap or a poll whose handler changes
     * nothing on screen, like one that only dispatches a job, would never have its fakes
     * recorded. While a test's fakes are in place, forgetting the last frame makes the next
     * one go out, so tree() runs after every render, as on 4.6. NativePHP's memo of each
     * node is left alone, so the frame still reuses the nodes that did not change, and the
     * extension drops it before the native side when nothing did.
     */
    private static function republish(Mirror $mirror): void
    {
        if (! $mirror->testing()) {
            return;
        }

        // 4.6 has no such property, and publishes every frame.
        if (self::$publishedFrame === null) {
            if (! property_exists(NativeComponent::class, 'publishedFrame')) {
                return;
            }

            self::$publishedFrame = new ReflectionProperty(NativeComponent::class, 'publishedFrame');
        }

        $component = NativeComponent::active();

        if ($component !== null) {
            self::$publishedFrame->setValue($component, null);
        }
    }
}
