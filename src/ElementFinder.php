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
            if (str_contains($element['label'], $text) || ($element['id'] ?? null) === $text) {
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
            $lines[] = "{$role}: {$element['label']}";
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
