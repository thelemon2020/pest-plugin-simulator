<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class AccessibilityTree
{
    /**
     * @return list<array{label: string, role: ?string, id: ?string, center: ?array{0: float, 1: float}}>
     */
    public static function summarize(string $json): array
    {
        $parsed = json_decode($json, true);

        if (! is_array($parsed)) {
            return [];
        }

        $nodes = [];

        if (array_is_list($parsed)) {
            foreach ($parsed as $node) {
                if (is_array($node)) {
                    self::walk($node, $nodes);
                }
            }
        } else {
            self::walk($parsed, $nodes);
        }
        $rows = [];

        foreach ($nodes as $node) {
            $label = self::label($node);

            if ($label === '') {
                continue;
            }

            $identifier = $node['AXUniqueId'] ?? $node['identifier'] ?? $node['resource-id'] ?? null;
            $identifier = is_string($identifier) && $identifier !== '' ? $identifier : null;
            $role = $node['type'] ?? $node['role'] ?? $node['AXRole'] ?? $node['class'] ?? null;

            $rows[] = [
                'label' => $label,
                'role' => is_string($role) ? self::role($role) : null,
                'id' => $identifier,
                'center' => self::center($node),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<array<mixed>>  $nodes
     */
    private static function walk(array $node, array &$nodes): void
    {
        $nodes[] = $node;

        foreach (['children', 'AXChildren', 'nodes', 'elements'] as $key) {
            if (! isset($node[$key]) || ! is_array($node[$key])) {
                continue;
            }

            foreach ($node[$key] as $child) {
                if (is_array($child)) {
                    self::walk($child, $nodes);
                }
            }
        }
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function label(array $node): string
    {
        foreach (['AXLabel', 'label', 'content-desc', 'text', 'AXValue', 'value', 'title'] as $key) {
            $value = $node[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @param  array<mixed>  $node
     * @return array{0: float, 1: float}|null
     */
    private static function center(array $node): ?array
    {
        $frame = $node['AXFrame'] ?? $node['frame'] ?? $node['bounds'] ?? null;

        if (is_string($frame)) {
            if (preg_match_all('/-?\d+(?:\.\d+)?/', $frame, $matches) !== false && count($matches[0]) >= 4) {
                $x = (float) $matches[0][0];
                $y = (float) $matches[0][1];
                $width = (float) $matches[0][2];
                $height = (float) $matches[0][3];

                if (str_contains($frame, '][')) {
                    $width -= $x;
                    $height -= $y;
                }

                if ($width > 0 && $height > 0) {
                    return [$x + $width / 2, $y + $height / 2];
                }
            }
        }

        if (is_array($frame)) {
            $x = (float) ($frame['x'] ?? $frame['X'] ?? 0);
            $y = (float) ($frame['y'] ?? $frame['Y'] ?? 0);
            $width = (float) ($frame['width'] ?? $frame['Width'] ?? 0);
            $height = (float) ($frame['height'] ?? $frame['Height'] ?? 0);

            if ($width > 0 && $height > 0) {
                return [$x + $width / 2, $y + $height / 2];
            }
        }

        return null;
    }

    private static function role(string $role): string
    {
        if (str_contains($role, 'Button')) {
            return 'Button';
        }

        if (str_contains($role, 'EditText') || str_contains($role, 'TextField')) {
            return 'TextField';
        }

        return $role;
    }
}
