<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;
use NativePhp\Simulator\Exceptions\AmbiguousMatch;
use NativePhp\Simulator\Exceptions\NoMatch;

/**
 * @phpstan-import-type Element from Driver
 * @phpstan-import-type Tappable from Driver
 * @phpstan-import-type OpenPrompt from Driver
 */
final class ElementFinder
{
    /**
     * The control a label names: its accessibility id, then an exact label, then a label
     * that contains the text. With $nth, the nth of those in reading order (top to bottom,
     * then left to right), counting from 1, instead of one picked by role.
     *
     * @param  list<Element>  $elements
     * @return Tappable
     */
    public function match(array $elements, string $target, ?int $nth = null): array
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
                // Android draws a dialog's buttons in capitals: DELETE for a button named
                // Delete. Without this, alert('Delete') found the title "Delete everything?"
                // by the words it contains, and tapped that instead.
                fn (array $element): bool => mb_strtolower($element['label']) === mb_strtolower($target),
                fn (array $element): bool => str_contains($element['label'], $target),
            ] as $predicate) {
                $matches = array_values(array_filter($pool, $predicate));

                if ($matches === []) {
                    continue;
                }

                return $nth === null ? $this->choose($matches, $target) : $this->nth($matches, $target, $nth);
            }
        }

        throw new NoMatch($target);
    }

    /**
     * What the prompt says in English, for when the device's own text can't be read.
     *
     * @var OpenPrompt
     */
    private const ENGLISH_PROMPT = ['titles' => ['Open in “%@”?'], 'open' => 'Open'];

    /**
     * The Open button of iOS's deep-link prompt, "Open in “App”?", while it is up.
     *
     * It is an alert with the prompt's title, the app's name filled in, and a Button in it
     * labelled Open. In English its words are known here. In any other language they come
     * from the device (see SchemeApproval), asked for only once an alert is up that the
     * English words don't match, since reading them the first time takes a moment.
     *
     * @param  list<Element>  $elements
     * @param  (Closure(): list<OpenPrompt>)|null  $prompts
     * @return Tappable|null
     */
    public function openDialogButton(array $elements, ?Closure $prompts = null): ?array
    {
        $alert = $this->within($elements, 'alert');

        if ($alert === []) {
            return null;
        }

        $english = $this->promptButton($alert, [self::ENGLISH_PROMPT]);

        if ($english !== null || $prompts === null) {
            return $english;
        }

        return $this->promptButton($alert, $prompts());
    }

    /**
     * @param  list<Element>  $alert
     * @param  list<OpenPrompt>  $prompts
     * @return Tappable|null
     */
    private function promptButton(array $alert, array $prompts): ?array
    {
        foreach ($prompts as $prompt) {
            if (! $this->titledBy($alert, $prompt['titles'])) {
                continue;
            }

            $buttons = array_values(array_filter(
                $alert,
                fn (array $element): bool => ($element['role'] ?? null) === 'Button'
                    && $element['label'] === $prompt['open']
                    && is_array($element['center'] ?? null),
            ));

            if (count($buttons) === 1) {
                /** @var Tappable $button */
                $button = $buttons[0];

                return $button;
            }
        }

        return null;
    }

    /**
     * @param  list<Element>  $elements
     * @param  list<string>  $titles
     */
    private function titledBy(array $elements, array $titles): bool
    {
        foreach ($elements as $element) {
            foreach ($titles as $title) {
                if (SchemeApproval::titled($element['label'], $title)) {
                    return true;
                }
            }
        }

        return false;
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
    public function hasValue(array $elements, string $target, string $value, ?int $nth = null): bool
    {
        try {
            return $this->valueOf($elements, $target, $nth) === $value;
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
    public function holds(array $elements, string $target, array $field, string $value, ?int $nth = null): bool
    {
        try {
            if ($this->shows($this->match($elements, $target, $nth), $value)) {
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
    public function valueOf(array $elements, string $target, ?int $nth = null): string
    {
        return $this->value($this->match($elements, $target, $nth));
    }

    /**
     * @param  list<Element>  $elements
     */
    public function isEnabled(array $elements, string $target, bool $enabled, ?int $nth = null): bool
    {
        try {
            $match = $this->match($elements, $target, $nth);
        } catch (NoMatch) {
            return false;
        }

        return ($match['enabled'] ?? true) === $enabled;
    }

    /**
     * @param  list<Element>  $elements
     */
    public function isChecked(array $elements, string $target, bool $checked, ?int $nth = null): bool
    {
        try {
            $match = $this->match($elements, $target, $nth);
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
    public function isSelected(array $elements, string $target, bool $selected, ?int $nth = null): bool
    {
        try {
            $match = $this->match($elements, $target, $nth);
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
        $button = $this->labeled($elements, 'photos', $label);

        if ($button !== null || ! $this->showsPhotos($elements)) {
            return $button;
        }

        // iOS 26 puts the picker's Cancel in a bar outside its photo grid. Its id is
        // Cancel in every language.
        foreach ($elements as $element) {
            if (($element['role'] ?? null) === 'Button' && ($element['id'] ?? null) === $label && is_array($element['center'] ?? null)) {
                /** @var Tappable $element */
                return $element;
            }
        }

        return null;
    }

    /**
     * @param  list<Element>  $elements
     */
    private function showsPhotos(array $elements): bool
    {
        return $this->within($elements, 'photos') !== [];
    }

    /**
     * @param  list<Element>  $elements
     * @return Tappable|null
     */
    public function firstPhoto(array $elements): ?array
    {
        /** @var list<Tappable> $photos isPhoto() keeps only elements with a center */
        $photos = array_values(array_filter(
            $elements,
            fn (array $element): bool => $this->isPhoto($element),
        ));

        if ($photos === []) {
            return null;
        }

        // iOS 26's picker puts the Photos app's icon in a banner above the grid, an image
        // with no label like the photos. Its tiles carry a PXGGridLayout id: when any do,
        // only they are photos.
        $tiles = array_values(array_filter(
            $photos,
            fn (array $photo): bool => str_starts_with((string) ($photo['id'] ?? ''), 'PXGGridLayout'),
        ));

        if ($tiles !== []) {
            $photos = $tiles;
        }

        // Android's picker names each tile, as "Photo taken on Oct 20, 2026, 5:03 PM". An
        // image it leaves unnamed, such as the handle at the top of its sheet, reads as a
        // plain "Photo" and is above every tile. When any tile is named, only they are photos.
        $named = array_values(array_filter(
            $photos,
            fn (array $photo): bool => str_starts_with($photo['label'], 'Photo taken'),
        ));

        if ($named !== []) {
            $photos = $named;
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
     * An alert or action sheet is up.
     *
     * @param  list<Element>  $elements
     */
    public function hasAlert(array $elements): bool
    {
        return $this->within($elements, 'alert') !== [];
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
     * The top of the iOS sheet that is up: the first control after the dimming view the
     * sheet lays over the screen behind it, which is its grabber. Null with no sheet.
     *
     * @param  list<Element>  $elements
     * @return Tappable|null
     */
    public function sheetTop(array $elements): ?array
    {
        $dimming = null;

        foreach ($elements as $index => $element) {
            if (($element['role'] ?? null) === 'UIDimmingView' || ($element['id'] ?? null) === 'PopoverDismissRegion') {
                $dimming = $index;
            }
        }

        if ($dimming === null) {
            return null;
        }

        $top = null;

        foreach (array_slice($elements, $dimming + 1) as $element) {
            if (is_array($element['center'] ?? null) && ($top === null || $element['center'][1] < $top['center'][1])) {
                /** @var Tappable $element */
                $top = $element;
            }
        }

        return $top;
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

    /** What plain text reads as: iOS's static text, and an Android TextView. */
    private const LABEL_ROLES = ['StaticText', 'android.widget.TextView'];

    /**
     * Whether $text is only the label of a Button among $matches: plain text, with the same
     * label, and its center inside that Button's frame. A control of its own, such as a
     * switch or a link on a card that is a Button with the same label, is not.
     *
     * @param  Tappable  $text
     * @param  list<Tappable>  $matches
     */
    private function drawsLabelOf(array $text, array $matches): bool
    {
        if (! in_array($text['role'], self::LABEL_ROLES, true)) {
            return false;
        }

        foreach ($matches as $control) {
            $frame = $control['frame'] ?? null;

            if ($control['role'] !== 'Button' || $control['label'] !== $text['label'] || ! is_array($frame)) {
                continue;
            }

            [$x, $y, $width, $height] = $frame;
            [$centerX, $centerY] = $text['center'];

            if ($centerX >= $x && $centerX <= $x + $width && $centerY >= $y && $centerY <= $y + $height) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Tappable>  $matches
     * @return Tappable
     */
    private function nth(array $matches, string $target, int $nth): array
    {
        // Android reads a NativePHP button twice: the Button, and the TextView inside it
        // that draws the same label. Counted apart, nth(2)->tap('Pay') on three orders
        // tapped the first order's Button, the second match from the top.
        $matches = array_values(array_filter(
            $matches,
            fn (array $match): bool => ! $this->drawsLabelOf($match, $matches),
        ));

        usort($matches, function (array $left, array $right): int {
            $vertical = $left['center'][1] <=> $right['center'][1];

            return $vertical !== 0 ? $vertical : $left['center'][0] <=> $right['center'][0];
        });

        return $matches[$nth - 1] ?? throw new NoMatch($target, count($matches) === 1
            ? "Found one [{$target}], so there is no number {$nth}."
            : 'Found '.count($matches)." [{$target}], so there is no number {$nth}.");
    }

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

        // A toggle and the caption beside it often say the same words. The toggle is the one
        // that checks: Android's toggle row, or an iOS Switch.
        if (count($pool) > 1) {
            $checkable = array_values(array_filter(
                $pool,
                fn (array $element): bool => ($element['checkable'] ?? false) === true,
            ));

            if (count($checkable) === 1) {
                return $checkable[0];
            }
        }

        // Android draws a tab or a button's words as a node of their own on top of the
        // control, in the same place. Matches that all sit on one spot are one control.
        if (count($pool) > 1 && $this->samePlace($pool)) {
            return $pool[0];
        }

        if (count($pool) > 1) {
            throw new AmbiguousMatch($target);
        }

        return $pool[0];
    }

    /**
     * @param  list<Tappable>  $matches
     */
    private function samePlace(array $matches): bool
    {
        [$x, $y] = $matches[0]['center'];

        foreach ($matches as $match) {
            if (abs($match['center'][0] - $x) > 2 || abs($match['center'][1] - $y) > 2) {
                return false;
            }
        }

        return true;
    }
}
