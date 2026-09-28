<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class AccessibilityTree
{
    /**
     * @return list<array{label: string, role: ?string, id: ?string, center: ?array{0: float, 1: float}, value: ?string, enabled: bool, selected: bool, checked: bool, chrome: ?string, webview: bool}>
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
            $role = $node['type'] ?? $node['role'] ?? $node['AXRole'] ?? $node['class'] ?? null;
            $role = is_string($role) ? self::role($role) : null;
            $value = self::value($node, $role);
            $label = self::label($node, $role);

            if ($label === '' && $value !== null && $value !== '') {
                $label = $value;
            }

            if ($label === '') {
                continue;
            }

            $identifier = $node['AXUniqueId'] ?? $node['identifier'] ?? $node['resource-id'] ?? null;
            $identifier = is_string($identifier) && $identifier !== '' ? $identifier : null;

            $rows[] = [
                'label' => $label,
                'role' => $role,
                'id' => $identifier,
                'center' => self::center($node),
                'value' => $value,
                'enabled' => self::enabled($node),
                'selected' => self::selected($node),
                'checked' => self::checked($node, $role),
                'chrome' => is_string($node['__chrome'] ?? null) ? $node['__chrome'] : null,
                'webview' => $role === 'WebView',
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: float, 1: float}
     */
    public static function viewport(string $json): array
    {
        $parsed = json_decode($json, true);
        $width = 0.0;
        $height = 0.0;

        if (is_array($parsed)) {
            self::measure($parsed, $width, $height);
        }

        return [$width, $height];
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<array<mixed>>  $nodes
     */
    private static function walk(array $node, array &$nodes, ?string $chrome = null): void
    {
        $chrome = self::chrome($node) ?? $chrome;
        $node['__chrome'] = $chrome;
        $nodes[] = $node;

        foreach (['children', 'AXChildren', 'nodes', 'elements'] as $key) {
            if (! isset($node[$key]) || ! is_array($node[$key])) {
                continue;
            }

            foreach ($node[$key] as $child) {
                if (is_array($child)) {
                    self::walk($child, $nodes, $chrome);
                }
            }
        }
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function label(array $node, ?string $role): string
    {
        foreach (['AXLabel', 'label', 'content-desc', 'title'] as $key) {
            $value = self::string($node[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        if ($role !== 'TextField') {
            return self::string($node['text'] ?? null) ?? '';
        }

        return '';
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function value(array $node, ?string $role): ?string
    {
        foreach (['AXValue', 'value'] as $key) {
            $value = self::string($node[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        if ($role === 'TextField') {
            return self::string($node['text'] ?? null) ?? '';
        }

        return null;
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function enabled(array $node): bool
    {
        foreach (['enabled', 'AXEnabled'] as $key) {
            if (! array_key_exists($key, $node) || $node[$key] === '' || $node[$key] === null) {
                continue;
            }

            return self::truthy($node[$key]);
        }

        return true;
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function selected(array $node): bool
    {
        if (self::truthy($node['selected'] ?? $node['AXSelected'] ?? false)) {
            return true;
        }

        $traits = $node['AXTraits'] ?? $node['traits'] ?? '';

        if (is_array($traits)) {
            $traits = implode(' ', array_map(strval(...), $traits));
        }

        return is_string($traits) && str_contains(strtolower($traits), 'selected');
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function checked(array $node, ?string $role): bool
    {
        if (array_key_exists('checked', $node) && self::truthy($node['checked'])) {
            return true;
        }

        if ($role !== 'Switch') {
            return false;
        }

        $value = $node['AXValue'] ?? $node['value'] ?? null;

        return $value === true || $value === 1 || $value === 1.0 || $value === '1' || $value === 'true';
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function chrome(array $node): ?string
    {
        $role = strtolower((string) ($node['type'] ?? $node['role'] ?? $node['AXRole'] ?? $node['class'] ?? ''));

        if (str_contains($role, 'navigationbar') || str_contains($role, 'toolbar') || str_contains($role, 'actionbar')) {
            return 'navigation';
        }

        if (str_contains($role, 'tabbar') || str_contains($role, 'bottomnavigation') || str_contains($role, 'tabwidget')) {
            return 'tab';
        }

        return null;
    }

    private static function string(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0 && $value !== 0.0;
        }

        if (! is_string($value)) {
            return false;
        }

        return ! in_array(strtolower($value), ['', 'false', '0', 'no'], true);
    }

    /**
     * @param  array<mixed>  $node
     * @return array{0: float, 1: float}|null
     */
    private static function center(array $node): ?array
    {
        $frame = self::frame($node);

        if ($frame === null) {
            return null;
        }

        return [$frame[0] + $frame[2] / 2, $frame[1] + $frame[3] / 2];
    }

    /**
     * @param  array<mixed>|list<mixed>  $node
     */
    private static function measure(array $node, float &$width, float &$height): void
    {
        if (array_is_list($node)) {
            foreach ($node as $child) {
                if (is_array($child)) {
                    self::measure($child, $width, $height);
                }
            }

            return;
        }

        $frame = self::frame($node);

        if ($frame !== null) {
            $width = max($width, $frame[0] + $frame[2]);
            $height = max($height, $frame[1] + $frame[3]);
        }

        foreach (['children', 'AXChildren', 'nodes', 'elements'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                self::measure($node[$key], $width, $height);
            }
        }
    }

    /**
     * @param  array<mixed>  $node
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private static function frame(array $node): ?array
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
                    return [$x, $y, $width, $height];
                }
            }
        }

        if (is_array($frame)) {
            $x = (float) ($frame['x'] ?? $frame['X'] ?? 0);
            $y = (float) ($frame['y'] ?? $frame['Y'] ?? 0);
            $width = (float) ($frame['width'] ?? $frame['Width'] ?? 0);
            $height = (float) ($frame['height'] ?? $frame['Height'] ?? 0);

            if ($width > 0 && $height > 0) {
                return [$x, $y, $width, $height];
            }
        }

        return null;
    }

    private static function role(string $role): string
    {
        if (str_contains($role, 'WebView')) {
            return 'WebView';
        }

        if (str_contains($role, 'Switch') || str_contains($role, 'CheckBox') || str_contains($role, 'Toggle')) {
            return 'Switch';
        }

        if (str_contains($role, 'EditText') || str_contains($role, 'TextField')) {
            return 'TextField';
        }

        if (str_contains($role, 'Button')) {
            return 'Button';
        }

        return $role;
    }
}
