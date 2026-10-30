<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Codegen;

use NativePhp\Simulator\Driver;
use NativePhp\Simulator\ElementFinder;
use NativePhp\Simulator\Exceptions\AmbiguousMatch;
use NativePhp\Simulator\Exceptions\NoMatch;
use NativePhp\Simulator\Exceptions\SimulatorException;

/**
 * How a recorded test names the control a click landed on. A name is only kept when
 * ElementFinder finds that same control by it, so the test does what the click did.
 *
 * @phpstan-import-type Element from Driver
 *
 * @phpstan-type Target array{target: string, nth: int|null, within: string|null}
 */
final class Selector
{
    /** The most controls nth() counts through before giving up on a name. */
    private const MOST = 50;

    /** How far from a control without a frame a click may land and still be on it. */
    private const REACH = 24.0;

    public function __construct(private readonly ElementFinder $finder = new ElementFinder) {}

    /**
     * The control under a point: the smallest one whose frame holds it. One with a name wins
     * over one a test could not name. Then, with $touch, one you can touch wins over the text
     * inside it, since that is what a tap there reaches.
     *
     * @param  list<Element>  $elements
     * @return Element|null
     */
    public function at(array $elements, float $x, float $y, bool $touch = true): ?array
    {
        $under = array_values(array_filter(
            $elements,
            fn (array $element): bool => is_array($element['center'] ?? null) && $this->holds($element, $x, $y),
        ));

        // Android reads a checkbox's square apart from its label, and a list row's button apart
        // from its text, and only the label and the text have a name.
        $named = array_values(array_filter($under, fn (array $element): bool => self::label($element) !== '' || self::chosenId($element) !== null));
        $under = $named === [] ? $under : $named;

        if ($touch) {
            $touchable = array_values(array_filter($under, fn (array $element): bool => self::touchable($element)));
            $under = $touchable === [] ? $under : $touchable;
        }

        usort($under, fn (array $a, array $b): int => $this->area($a) <=> $this->area($b));

        return $under[0] ?? null;
    }

    /**
     * The shortest name that finds $element again: its accessibility id, its label, its
     * label inside a container with an id, then its label counted with nth().
     *
     * @param  list<Element>  $elements
     * @param  Element  $element
     * @return Target
     */
    public function for(array $elements, array $element): array
    {
        $names = array_values(array_unique(array_filter(
            [self::chosenId($element), self::label($element)],
            fn (?string $name): bool => $name !== null && $name !== '',
        )));

        if ($names === []) {
            throw new SimulatorException('This control has no label or accessibility id, so a test cannot name it. Give it an a11y-label or an id.');
        }

        foreach ($names as $name) {
            if ($this->finds($elements, $name, null, $element)) {
                return ['target' => $name, 'nth' => null, 'within' => null];
            }
        }

        foreach ($this->containers($elements, $element) as $container) {
            $inside = $this->finder->inside($elements, $container['frame'] ?? [0, 0, 0, 0]);

            foreach ($names as $name) {
                if ($this->finds($inside, $name, null, $element)) {
                    return ['target' => $name, 'nth' => null, 'within' => (string) $container['id']];
                }
            }
        }

        foreach ($names as $name) {
            for ($nth = 1; $nth <= self::MOST; $nth++) {
                try {
                    $match = $this->finder->match($elements, $name, $nth);
                } catch (NoMatch) {
                    break;
                }

                if ($this->reaches($match, $element)) {
                    return ['target' => $name, 'nth' => $nth, 'within' => null];
                }
            }
        }

        throw new SimulatorException("No name finds this control again: [{$names[0]}] finds another one first. Give it an accessibility id.");
    }

    /**
     * Whether a control is one a tap does something to, rather than text or a picture.
     *
     * @param  Element  $element
     */
    public static function touchable(array $element): bool
    {
        return in_array($element['role'], ['Button', 'Switch', 'TextField', 'TextView', 'Link', 'Cell', 'Slider'], true)
            || ($element['checkable'] ?? false);
    }

    /**
     * The label that names a control. iOS reads a switch twice: the labelled one, and the
     * control inside it, whose label is only its value, 0 or 1. That label changes when the
     * switch is flipped, so it names nothing. A field's placeholder can be its label and its
     * value at once, and does name it.
     *
     * @param  Element  $element
     */
    private static function label(array $element): string
    {
        $value = $element['value'] ?? null;

        if (($element['role'] === 'Switch' || ($element['checkable'] ?? false)) && is_string($value) && $element['label'] === $value) {
            return '';
        }

        return $element['label'];
    }

    /**
     * An id the app gave the control. Android names some of its own views, such as
     * android:id/button1, and iOS gives a nav bar button its icon's name, chevron.backward.
     * Neither says anything about the app.
     *
     * @param  Element  $element
     */
    private static function chosenId(array $element): ?string
    {
        $id = $element['id'] ?? null;

        if ($id === null || ($element['chrome'] ?? null) !== null) {
            return null;
        }

        return str_contains($id, ':') || str_contains($id, '/') ? null : $id;
    }

    /**
     * @param  list<Element>  $elements
     * @param  Element  $element
     */
    private function finds(array $elements, string $name, ?int $nth, array $element): bool
    {
        try {
            return $this->reaches($this->finder->match($elements, $name, $nth), $element);
        } catch (NoMatch|AmbiguousMatch) {
            return false;
        }
    }

    /**
     * The controls with an app-chosen id whose frame holds $element, smallest first.
     *
     * @param  list<Element>  $elements
     * @param  Element  $element
     * @return list<Element>
     */
    private function containers(array $elements, array $element): array
    {
        [$x, $y] = $element['center'] ?? [0, 0];

        $containers = array_values(array_filter(
            $elements,
            fn (array $candidate): bool => ! self::same($candidate, $element)
                && is_array($candidate['frame'] ?? null)
                && self::chosenId($candidate) !== null
                && $this->holds($candidate, (float) $x, (float) $y)
                && $this->finds($elements, (string) $candidate['id'], null, $candidate),
        ));

        usort($containers, fn (array $a, array $b): int => $this->area($a) <=> $this->area($b));

        return $containers;
    }

    /**
     * Whether a tap on $match lands on $element: it is $element, or it has the same label and
     * $element is inside it. Android reads a tab as the tab and its text, both with one label.
     *
     * @param  Element  $match
     * @param  Element  $element
     */
    private function reaches(array $match, array $element): bool
    {
        [$x, $y] = $element['center'] ?? [INF, INF];

        return self::same($match, $element) || ($match['label'] === $element['label'] && $this->holds($match, (float) $x, (float) $y));
    }

    /**
     * @param  Element  $element
     */
    private function holds(array $element, float $x, float $y): bool
    {
        $frame = $element['frame'] ?? null;

        if (! is_array($frame)) {
            [$cx, $cy] = $element['center'] ?? [INF, INF];

            return hypot($cx - $x, $cy - $y) <= self::REACH;
        }

        [$left, $top, $width, $height] = $frame;

        return $x >= $left && $x <= $left + $width && $y >= $top && $y <= $top + $height;
    }

    /**
     * @param  Element  $element
     */
    private function area(array $element): float
    {
        $frame = $element['frame'] ?? null;

        return is_array($frame) ? $frame[2] * $frame[3] : (2 * self::REACH) ** 2;
    }

    /**
     * @param  Element  $a
     * @param  Element  $b
     */
    private static function same(array $a, array $b): bool
    {
        return $a['label'] === $b['label']
            && $a['role'] === $b['role']
            && ($a['id'] ?? null) === ($b['id'] ?? null)
            && $a['center'] == $b['center'];
    }
}
