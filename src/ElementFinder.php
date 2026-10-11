<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\AmbiguousMatch;
use NativePhp\Simulator\Exceptions\NoMatch;

/**
 * @phpstan-import-type Element from Driver
 * @phpstan-import-type Tappable from Driver
 */
final class ElementFinder
{
    /**
     * @param  list<Element>  $elements
     * @return Tappable
     */
    public function match(array $elements, string $target): array
    {
        $tappable = array_values(array_filter(
            $elements,
            fn (array $element): bool => is_array($element['center'] ?? null),
        ));

        // A screen's title is routinely mirrored onto the nav bar verbatim, so a nav/tab
        // bar's own passive label (a plain StaticText, never a Button or other interactive
        // role) can EXACT-match a target before a real on-screen control ever gets a chance
        // to — the three tiers below return as soon as ANY tier has a match, so an exact
        // chrome hit short-circuits past a lower-tier CONTAINS match from the actual control
        // entirely, before `choose()`'s own chrome-vs-content preference (below) is ever
        // consulted, since that only resolves ties WITHIN one tier's pool, not across tiers.
        // Concretely: SegmentEditor's rename control carries a11y-label "Rename or recolour
        // Top shelf"; `tap('Top shelf')` EXACT-matched the nav bar's title "Top shelf" at
        // tier 2 and returned immediately, never reaching tier 3 where the real control would
        // have matched by CONTAINS — a tap that found a real coordinate, dispatched with no
        // error, and did nothing, because chrome consumed it before content was ever tried.
        // Real interactive chrome (a Back button, a toolbar action, a tab item) keeps its
        // Button role and so is NOT excluded here — only inert nav/tab bar decoration is.
        $content = array_values(array_filter(
            $tappable,
            fn (array $element): bool => ! in_array($element['chrome'] ?? null, ['navigation', 'tab'], true)
                || $element['role'] === 'Button'
                || in_array($element['role'], self::INTERACTIVE_ROLES, true),
        ));

        foreach ([$content, $tappable] as $pool) {
            foreach ([
                fn (array $element): bool => ($element['id'] ?? null) === $target,
                fn (array $element): bool => $element['label'] === $target,
                fn (array $element): bool => str_contains($element['label'], $target),
            ] as $predicate) {
                $matches = array_values(array_filter($pool, $predicate));

                if ($matches === []) {
                    continue;
                }

                return $this->choose($matches, $target);
            }
        }

        throw new NoMatch($target);
    }

    /**
     * @param  list<Element>  $elements
     * @return Tappable|null
     */
    public function openDialogButton(array $elements): ?array
    {
        $prompt = false;

        foreach ($elements as $element) {
            if (str_contains($element['label'], 'Open in')) {
                $prompt = true;
                break;
            }
        }

        if (! $prompt) {
            return null;
        }

        try {
            $match = $this->match($elements, 'Open');
        } catch (NoMatch|AmbiguousMatch) {
            return null;
        }

        return $match['role'] === 'Button' ? $match : null;
    }

    /**
     * The system chevron. A pushed screen has one Back button; a screen that
     * also has its own control named Back is left for the edge swipe.
     *
     * @param  list<Element>  $elements
     * @return Tappable|null
     */
    public function navigationBack(array $elements): ?array
    {
        $inBar = $this->labeled($elements, 'navigation', 'Back');

        if ($inBar !== null && ($inBar['role'] ?? null) === 'Button') {
            return $inBar;
        }

        $buttons = array_values(array_filter(
            $elements,
            fn (array $element): bool => ($element['role'] ?? null) === 'Button'
                && $element['label'] === 'Back'
                && is_array($element['center'] ?? null),
        ));

        if (count($buttons) === 1) {
            /** @var Tappable $button */
            $button = $buttons[0];

            return $button;
        }

        if ($buttons !== []) {
            return null;
        }

        $icons = array_values(array_filter(
            $elements,
            fn (array $element): bool => in_array($element['label'], ['arrow_back', 'Navigate up'], true)
                && is_array($element['center'] ?? null),
        ));

        if (count($icons) !== 1) {
            return null;
        }

        /** @var Tappable $icon */
        $icon = $icons[0];

        return $icon;
    }

    /**
     * @param  list<Element>  $elements
     */
    public function sees(array $elements, string $text): bool
    {
        foreach ($elements as $element) {
            if (str_contains($element['label'], $text) || str_contains((string) ($element['value'] ?? ''), $text) || ($element['id'] ?? null) === $text) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Element>  $elements
     * @param  list<string>  $texts
     */
    public function seesAll(array $elements, array $texts): bool
    {
        return $this->missing($elements, $texts) === [];
    }

    /**
     * @param  list<Element>  $elements
     * @param  list<string>  $texts
     * @return list<string>
     */
    public function missing(array $elements, array $texts): array
    {
        return array_values(array_filter(
            $texts,
            fn (string $text): bool => ! $this->sees($elements, $text),
        ));
    }

    /**
     * @param  list<Element>  $elements
     */
    public function hasValue(array $elements, string $target, string $value): bool
    {
        try {
            return $this->valueOf($elements, $target) === $value;
        } catch (NoMatch) {
            return false;
        }
    }

    /**
     * Whether the field `type()` tapped now holds `$value`. A field with no label or id of
     * its own is named by its placeholder, which iOS reports as the field's value only
     * while it is empty. Once text goes in, nothing in the tree carries that name, so the
     * field is found again as the text field nearest where it was tapped.
     *
     * @param  list<Element>  $elements
     * @param  Tappable  $field
     */
    public function holds(array $elements, string $target, array $field, string $value): bool
    {
        try {
            if ($this->shows($this->match($elements, $target), $value)) {
                return true;
            }
        } catch (NoMatch) {
        }

        if (! in_array($field['role'], self::TEXT_ROLES, true)) {
            return false;
        }

        $nearest = null;
        $distance = INF;

        foreach ($elements as $element) {
            $center = $element['center'] ?? null;

            if (! in_array($element['role'], self::TEXT_ROLES, true) || ! is_array($center)) {
                continue;
            }

            $away = hypot($center[0] - $field['center'][0], $center[1] - $field['center'][1]);

            if ($away < $distance) {
                $nearest = $element;
                $distance = $away;
            }
        }

        return $nearest !== null && $this->shows($nearest, $value);
    }

    /**
     * A secure field reads back as one bullet per character until it is shown, so that is
     * all a typed password can be checked against. Android keeps the field secure once it
     * is shown, and then reads back the text itself.
     *
     * @param  Element  $field
     */
    private function shows(array $field, string $value): bool
    {
        $shown = $this->value($field);

        if ($shown === $value) {
            return true;
        }

        return ($field['secure'] ?? false) === true && $shown === str_repeat("\u{2022}", mb_strlen($value));
    }

    /**
     * @param  list<Element>  $elements
     */
    public function valueOf(array $elements, string $target): string
    {
        return $this->value($this->match($elements, $target));
    }

    /**
     * @param  list<Element>  $elements
     */
    public function isEnabled(array $elements, string $target, bool $enabled): bool
    {
        try {
            $match = $this->match($elements, $target);
        } catch (NoMatch) {
            return false;
        }

        return ($match['enabled'] ?? true) === $enabled;
    }

    /**
     * @param  list<Element>  $elements
     */
    public function isChecked(array $elements, string $target, bool $checked): bool
    {
        try {
            $match = $this->match($elements, $target);
        } catch (NoMatch) {
            return false;
        }

        return ($match['checked'] ?? false) === $checked;
    }

    /**
     * Whether a control carries the `.isSelected` accessibility trait — how a
     * `<native:chip>` reports its own on/off state (`AccessibilityTree::checked()` only
     * recognizes role `Switch`, which a chip is not; it's a `Button`). Distinct from
     * `isChecked()`, which answers a different question for a different kind of control.
     *
     * @param  list<Element>  $elements
     */
    public function isSelected(array $elements, string $target, bool $selected): bool
    {
        try {
            $match = $this->match($elements, $target);
        } catch (NoMatch) {
            return false;
        }

        return ($match['selected'] ?? false) === $selected;
    }

    /**
     * @param  list<Element>  $elements
     */
    public function navTitle(array $elements, string $title): bool
    {
        foreach ($elements as $element) {
            if (($element['chrome'] ?? null) === 'navigation' && $element['label'] === $title) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Element>  $elements
     */
    public function tabActive(array $elements, string $label): bool
    {
        foreach ($elements as $element) {
            if (($element['selected'] ?? false) !== true) {
                continue;
            }

            if (($element['id'] ?? null) === $label || $element['label'] === $label) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Element>  $elements
     */
    public function navigatedTo(array $elements, string $path): bool
    {
        $path = '/'.trim($path, '/');
        $segment = basename($path);

        foreach ($elements as $element) {
            $id = $element['id'] ?? null;

            if ($id === $path || $id === ltrim($path, '/')) {
                return true;
            }

            if (($element['chrome'] ?? null) !== 'navigation') {
                continue;
            }

            if ($element['label'] === $path || strcasecmp($element['label'], $segment) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Element>  $elements
     * @return Tappable|null
     */
    public function alertButton(array $elements, string $label): ?array
    {
        return $this->labeled($elements, 'alert', $label);
    }

    /**
     * @param  list<Element>  $elements
     */
    public function sharing(array $elements): bool
    {
        return $this->within($elements, 'share') !== [];
    }

    /**
     * @param  list<Element>  $elements
     * @return Tappable|null
     */
    public function shareButton(array $elements, string $label): ?array
    {
        return $this->labeled($elements, 'share', $label);
    }

    /**
     * @param  list<Element>  $elements
     * @return Tappable|null
     */
    public function shareDismiss(array $elements): ?array
    {
        foreach (['Close', 'Cancel'] as $label) {
            $button = $this->shareButton($elements, $label);

            if ($button !== null) {
                return $button;
            }
        }

        return null;
    }

    /**
     * @param  list<Element>  $elements
     * @return Tappable|null
     */
    public function photoButton(array $elements, string $label): ?array
    {
        return $this->labeled($elements, 'photos', $label);
    }

    /**
     * @param  list<Element>  $elements
     * @return Tappable|null
     */
    public function firstPhoto(array $elements): ?array
    {
        $photos = array_values(array_filter(
            $elements,
            fn (array $element): bool => $this->isPhoto($element),
        ));

        if ($photos === []) {
            return null;
        }

        usort($photos, function (array $left, array $right): int {
            $vertical = $left['center'][1] <=> $right['center'][1];

            return $vertical !== 0 ? $vertical : $left['center'][0] <=> $right['center'][0];
        });

        return $photos[0];
    }

    /**
     * @param  list<Element>  $elements
     * @return Tappable|null
     */
    public function photoConfirm(array $elements): ?array
    {
        foreach (['Add', 'Done'] as $label) {
            $button = $this->photoButton($elements, $label);

            if ($button !== null) {
                return $button;
            }
        }

        return null;
    }

    /**
     * @param  list<Element>  $elements
     */
    public function hasWebView(array $elements): bool
    {
        foreach ($elements as $element) {
            if (($element['webview'] ?? false) === true || ($element['role'] ?? null) === 'WebView') {
                return true;
            }
        }

        return false;
    }

    /**
     * An iOS sheet is up: the dimming view it lays over the screen behind it. iOS names that
     * view by its class and gives it an identifier, neither of them translated.
     *
     * @param  list<Element>  $elements
     */
    public function hasSheet(array $elements): bool
    {
        foreach ($elements as $element) {
            if (($element['role'] ?? null) === 'UIDimmingView' || ($element['id'] ?? null) === 'PopoverDismissRegion') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Element>  $elements
     */
    public function describe(array $elements): string
    {
        $lines = [];
        $unlabeled = [];

        foreach ($elements as $element) {
            $role = $element['role'] ?? 'Element';
            $id = $element['id'] ?? null;
            $id = is_string($id) && $id !== '' ? $id : null;

            if ($element['label'] === '') {
                $line = $id !== null ? "{$role}: [{$id}]" : "{$role}: (no label)";
                $unlabeled[] = $id !== null ? "{$role} [{$id}]" : $role;
            } else {
                $line = "{$role}: {$element['label']}";
            }

            $value = $element['value'] ?? null;

            if (is_string($value) && $value !== '' && $value !== $element['label']) {
                $line .= " = {$value}";
            }

            $lines[] = $line;
        }

        if ($lines === []) {
            return '(no labels)';
        }

        $body = implode("\n", $lines);

        if ($unlabeled === []) {
            return $body;
        }

        return $body."\n\nThese controls have no accessibility label: ".implode(', ', $unlabeled).'.';
    }

    /**
     * @param  list<Element>  $elements
     * @return Tappable|null
     */
    private function labeled(array $elements, string $chrome, string $label): ?array
    {
        try {
            return $this->match($this->within($elements, $chrome), $label);
        } catch (NoMatch) {
            return null;
        }
    }

    /**
     * @param  list<Element>  $elements
     * @return list<Element>
     */
    private function within(array $elements, string $chrome): array
    {
        return array_values(array_filter(
            $elements,
            fn (array $element): bool => ($element['chrome'] ?? null) === $chrome,
        ));
    }

    /**
     * @param  Element  $element
     */
    private function value(array $element): string
    {
        $value = $element['value'] ?? null;

        if (is_string($value)) {
            return $value;
        }

        return $element['role'] === 'TextField' ? $element['label'] : '';
    }

    /**
     * @param  Element  $element
     */
    private function isPhoto(array $element): bool
    {
        if (($element['chrome'] ?? null) !== 'photos' || ! is_array($element['center'] ?? null)) {
            return false;
        }

        if (in_array($element['label'], ['Photos', 'Recents', 'Photo Library', 'Cancel', 'Add', 'Done', 'Close', 'Back'], true)) {
            return false;
        }

        return ($element['role'] ?? null) === 'Image' || str_starts_with($element['label'], 'Photo');
    }

    /**
     * A control whose own label legitimately collides with a plain caption beside it — a
     * floating label on a multiline field, or a row's visible headline duplicated onto its
     * own toggle for VoiceOver. Checked only once nothing has already won on being the lone
     * `Button` in the match set.
     */
    private const INTERACTIVE_ROLES = ['TextField', 'TextView', 'Switch'];

    private const TEXT_ROLES = ['TextField', 'TextView'];

    /**
     * @param  list<Tappable>  $matches
     * @return Tappable
     */
    private function choose(array $matches, string $target): array
    {
        $buttons = array_values(array_filter(
            $matches,
            fn (array $element): bool => $element['role'] === 'Button',
        ));

        $pool = $buttons === [] ? $matches : $buttons;

        if (count($pool) > 1) {
            $interactive = array_values(array_filter(
                $pool,
                fn (array $element): bool => in_array($element['role'], self::INTERACTIVE_ROLES, true),
            ));

            if (count($interactive) === 1) {
                return $interactive[0];
            }
        }

        // A screen's title is routinely mirrored onto the nav bar verbatim (this app, like
        // most, names a screen after whatever it's showing — "Top shelf" the heading IS
        // "Top shelf" the nav title), which makes it collide with on-screen content sharing
        // the same exact string. Neither is a Button and neither is an INTERACTIVE_ROLES
        // control if the real target is a bare pressable Text, so without this the pool
        // reaches the ambiguity check below and either throws or — worse — silently returns
        // whichever happened to iterate first, which can be the inert nav title: a tap that
        // finds a real coordinate, dispatches with no error, and does nothing, because chrome
        // consumed it instead of the content it was decorating.
        if (count($pool) > 1) {
            $content = array_values(array_filter(
                $pool,
                fn (array $element): bool => ($element['chrome'] ?? null) === null,
            ));

            if (count($content) === 1) {
                return $content[0];
            }
        }

        if (count($pool) > 1) {
            throw new AmbiguousMatch($target);
        }

        return $pool[0];
    }
}
