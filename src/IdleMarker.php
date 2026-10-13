<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

/**
 * An element an app can show while it is tested, saying how many of the events it sent to
 * PHP (a tap, a keystroke, a toggle) PHP has not taken yet. With it, a step waits until the
 * app has answered, instead of until the screen looks still.
 *
 * Its accessibility id is `nativephp-pending` and its label is the count. iOS reads the id
 * as the element's identifier, Android as its resource id (a Compose testTag, with
 * testTagsAsResourceId). Nothing else on screen is changed by it.
 *
 * @phpstan-import-type Element from Driver
 */
final class IdleMarker
{
    public const ID = 'nativephp-pending';

    /**
     * The elements without the marker, and the count it shows: null when the app shows no
     * marker at all.
     *
     * @param  list<Element>  $elements
     * @return array{0: list<Element>, 1: int|null}
     */
    public static function split(array $elements): array
    {
        $pending = null;
        $kept = [];

        foreach ($elements as $element) {
            if (($element['id'] ?? null) !== self::ID) {
                $kept[] = $element;

                continue;
            }

            $count = trim($element['label'] !== '' ? $element['label'] : (string) ($element['value'] ?? ''));
            $pending = ctype_digit($count) ? (int) $count : 0;
        }

        return [$kept, $pending];
    }
}
