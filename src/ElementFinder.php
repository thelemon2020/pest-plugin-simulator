<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\AmbiguousMatch;
use NativePhp\Simulator\Exceptions\NoMatch;

final class ElementFinder
{
    /**
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float, 1: float}}>  $elements
     * @return array{label: string, role: ?string, id: ?string, center: array{0: float, 1: float}}
     */
    public function match(array $elements, string $target): array
    {
        $tappable = array_values(array_filter(
            $elements,
            fn (array $element): bool => is_array($element['center'] ?? null),
        ));

        foreach ([
            fn (array $element): bool => ($element['id'] ?? null) === $target,
            fn (array $element): bool => $element['label'] === $target,
            fn (array $element): bool => str_contains($element['label'], $target),
        ] as $predicate) {
            $matches = array_values(array_filter($tappable, $predicate));

            if ($matches === []) {
                continue;
            }

            return $this->choose($matches, $target);
        }

        throw new NoMatch($target, $elements);
    }

    /**
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float, 1: float}}>  $elements
     * @return array{label: string, role: ?string, id: ?string, center: array{0: float, 1: float}}|null
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
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float, 1: float}}>  $elements
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
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float, 1: float}, value?: ?string}>  $elements
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
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float, 1: float}, value?: ?string}>  $elements
     */
    public function valueOf(array $elements, string $target): string
    {
        $match = $this->match($elements, $target);
        $value = $match['value'] ?? null;

        if (is_string($value)) {
            return $value;
        }

        return $match['role'] === 'TextField' ? $match['label'] : '';
    }

    /**
     * @param  list<array{label: string, role: ?string, id: ?string, enabled?: bool}>  $elements
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
     * @param  list<array{label: string, role: ?string, id: ?string, checked?: bool}>  $elements
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
     * @param  list<array{label: string, role: ?string, id: ?string, chrome?: ?string}>  $elements
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
     * @param  list<array{label: string, role: ?string, id: ?string, selected?: bool}>  $elements
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
     * @param  list<array{label: string, role: ?string, id: ?string, chrome?: ?string}>  $elements
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
     * @param  list<array{label: string, role: ?string, webview?: bool}>  $elements
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
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float, 1: float}}>  $elements
     */
    public function describe(array $elements): string
    {
        $lines = [];

        foreach ($elements as $element) {
            $role = $element['role'] ?? 'Element';
            $line = "{$role}: {$element['label']}";
            $value = $element['value'] ?? null;

            if (is_string($value) && $value !== '' && $value !== $element['label']) {
                $line .= " = {$value}";
            }

            $lines[] = $line;
        }

        return $lines === [] ? '(no labels)' : implode("\n", $lines);
    }

    /**
     * @param  list<array{label: string, role: ?string, id: ?string, center: array{0: float, 1: float}}>  $matches
     * @return array{label: string, role: ?string, id: ?string, center: array{0: float, 1: float}}
     */
    private function choose(array $matches, string $target): array
    {
        $buttons = array_values(array_filter(
            $matches,
            fn (array $element): bool => $element['role'] === 'Button',
        ));

        $pool = $buttons === [] ? $matches : $buttons;

        if (count($pool) > 1) {
            throw new AmbiguousMatch($target, $pool);
        }

        return $pool[0];
    }
}
