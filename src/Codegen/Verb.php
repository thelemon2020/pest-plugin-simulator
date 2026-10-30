<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Codegen;

use Closure;
use NativePhp\Simulator\Driver;
use NativePhp\Simulator\Exceptions\SimulatorException;

/**
 * Which call a click on a control records: what a tap on it does, or what checking it
 * looks at.
 *
 * @phpstan-import-type Element from Driver
 * @phpstan-import-type Target from Selector
 */
final class Verb
{
    /**
     * @param  Element  $element
     * @param  Closure(): Target  $at
     */
    public static function touch(array $element, Closure $at, ?string $text = null): Step
    {
        $chrome = $element['chrome'] ?? null;

        // Tabs and alert buttons are found by their own rules, by label, not by nth() or within().
        if ($chrome === 'tab') {
            return new Step('changeTab', [$element['label']]);
        }

        // iOS's nav bar Back, and arrow_back, the icon NativePHP draws for Back on Android.
        if (($chrome === 'navigation' && $element['label'] === 'Back') || ($element['role'] === 'Button' && $element['label'] === 'arrow_back')) {
            return new Step('goBack');
        }

        if ($chrome === 'alert') {
            return new Step('alert', [$element['label']]);
        }

        if (self::field($element)) {
            if ($text === null || $text === '') {
                throw new SimulatorException('Type the text to enter into this field first.');
            }

            return self::at('type', [$text], $at);
        }

        // Android reads a checkbox as a switch, so the two are recorded alike. toggle() turns
        // either. A checkable Button is a radio option, which a tap picks.
        if ($element['role'] === 'Switch' || (($element['checkable'] ?? false) && $element['role'] !== 'Button')) {
            return self::at('toggle', [! ($element['checked'] ?? false)], $at);
        }

        return self::at('tap', [], $at);
    }

    /**
     * @param  Element  $element
     * @param  Closure(): Target  $at
     */
    public static function check(array $element, Closure $at): Step
    {
        $chrome = $element['chrome'] ?? null;

        if ($chrome === 'navigation' && $element['role'] !== 'Button') {
            return new Step('assertNavTitle', [$element['label']]);
        }

        if ($chrome === 'tab' && ($element['selected'] ?? false)) {
            return new Step('assertTabActive', [$element['label']]);
        }

        // An empty field reads '' on Android and its placeholder on iOS. Neither is a value
        // worth checking, and the placeholder is still text to see.
        $value = $element['value'] ?? null;

        if (in_array($element['role'], ['TextField', 'TextView'], true) && is_string($value) && $value !== '' && $value !== $element['label']) {
            return self::at('assertValue', [$value], $at);
        }

        if ($element['role'] === 'Switch' || ($element['checkable'] ?? false)) {
            return self::at(($element['checked'] ?? false) ? 'assertChecked' : 'assertNotChecked', [], $at);
        }

        $text = $element['label'] !== '' ? $element['label'] : (string) ($element['value'] ?? '');

        if ($text === '') {
            throw new SimulatorException('This control shows no text to check for.');
        }

        return new Step('assertSee', [$text]);
    }

    /**
     * Why a tap on the control cannot be recorded, for a picker or a slider, whose calls
     * take a value a click does not give. Android reads each as two controls in one spot:
     * a picker as its label over a field holding the choice, and a slider as its label
     * over the bar, which says its range.
     *
     * @param  list<Element>  $elements
     * @param  Element  $element
     */
    public static function unrecorded(array $elements, array $element): ?string
    {
        $spot = array_values(array_filter(
            $elements,
            fn (array $other): bool => is_array($other['center'] ?? null) && is_array($element['center'] ?? null)
                && hypot($other['center'][0] - $element['center'][0], $other['center'][1] - $element['center'][1]) <= 1.0,
        ));
        $named = array_values(array_filter($spot, fn (array $other): bool => $other['label'] !== '' && ! self::field($other) && ! is_numeric($other['label'])));
        $label = $named[0]['label'] ?? 'Label';

        foreach ($spot as $other) {
            if (($other['range'] ?? null) !== null || $other['role'] === 'Slider' || str_contains($other['role'] ?? '', 'SeekBar')) {
                return "Sliders are not recorded yet. Write ->slide('{$label}', …) by hand.";
            }
        }

        if (self::field($element) && $named !== [] && $named[0]['label'] !== $element['label']) {
            return "Pickers are not recorded yet. Write ->select('{$label}', …) by hand.";
        }

        return null;
    }

    /**
     * Whether a tap on the control types into it.
     *
     * @param  Element  $element
     */
    public static function field(array $element): bool
    {
        return in_array($element['role'], ['TextField', 'TextView'], true) && ! ($element['checkable'] ?? false);
    }

    /**
     * A step on the control $at names, which comes before the rest of $arguments.
     *
     * @param  list<string|bool>  $arguments
     * @param  Closure(): Target  $at
     */
    private static function at(string $verb, array $arguments, Closure $at): Step
    {
        $target = $at();

        return new Step($verb, [$target['target'], ...$arguments], $target['nth'], $target['within']);
    }
}
