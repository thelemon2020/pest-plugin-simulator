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
        $selections = [];

        if (array_is_list($parsed)) {
            $parsed = self::prepareFlatNodes($parsed);

            foreach ($parsed as $node) {
                if (is_array($node)) {
                    $inherited = $node['__inherited'] ?? null;
                    self::walk($node, $nodes, is_string($inherited) ? $inherited : null);
                    self::selectionFrames($node, $selections);
                }
            }
        } else {
            self::walk($parsed, $nodes);
            self::selectionFrames($parsed, $selections);
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

            $chrome = is_string($node['__chrome'] ?? null) ? $node['__chrome'] : null;

            if ($label === '' && $role === 'Image' && $chrome === 'photos') {
                $label = 'Photo';
            }

            $identifier = $node['AXUniqueId'] ?? $node['identifier'] ?? $node['resource-id'] ?? null;
            $identifier = is_string($identifier) && $identifier !== '' ? $identifier : null;
            $center = self::center($node);

            if ($label === '' && $identifier === null && ! self::interactive($role, $center)) {
                continue;
            }

            $selected = self::selected($node);

            if (! $selected && self::covered($center, $selections)) {
                $selected = true;
            }

            $rows[] = [
                'label' => $label,
                'role' => $role,
                'id' => $identifier,
                'center' => $center,
                'value' => $value,
                'enabled' => self::enabled($node),
                'selected' => $selected,
                'checked' => self::checked($node, $role),
                'chrome' => $chrome,
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

        if (! is_array($parsed)) {
            return [0.0, 0.0];
        }

        $frames = [];
        self::collectFrames($parsed, $frames);
        $display = self::display($frames);

        if ($display !== null) {
            return $display;
        }

        $width = 0.0;
        $height = 0.0;
        self::measure($parsed, $width, $height);

        return self::cap($width, $height);
    }

    /**
     * The software keyboard is a window whose origin is the bottom of the
     * display, so a key's frame sits below the screen a tap will accept.
     * Shift that window up until its bottom edge is the bottom of the screen.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function keyPoint(string $json, string $label, float $screenWidth, float $screenHeight): ?array
    {
        $parsed = json_decode($json, true);

        if (! is_array($parsed)) {
            return null;
        }

        $roots = array_is_list($parsed) ? $parsed : [$parsed];
        $key = null;
        $host = null;

        foreach ($roots as $root) {
            if (! is_array($root)) {
                continue;
            }

            $key ??= self::findFrame($root, fn (array $node): bool => ($node['type'] ?? null) === 'Key' && ($node['label'] ?? $node['AXLabel'] ?? null) === $label);
            $host ??= self::findFrame($root, fn (array $node): bool => ($node['type'] ?? null) === 'UIInputSetHostView');
        }

        if ($key === null) {
            return null;
        }

        $x = $key[0] + ($key[2] / 2);
        $y = $key[1] + ($key[3] / 2);

        if ($y >= $screenHeight && $host !== null) {
            $y -= ($host[1] + $host[3]) - $screenHeight;
        }

        if ($x < 0 || $y < 0 || $x >= $screenWidth || $y >= $screenHeight) {
            return null;
        }

        return [$x, $y];
    }

    /**
     * Compose draws an outlined field as an EditText whose text is the value
     * and a TextView inside it whose text is the label. A checkbox is a
     * checkable view with the label on a child. The dump is flat, so containment
     * is how those belong together.
     *
     * @param  list<array<mixed>>  $nodes
     * @return list<array<mixed>>
     */
    private static function prepareFlatNodes(array $nodes): array
    {
        $frames = [];

        foreach ($nodes as $index => $node) {
            $frames[$index] = is_array($node) ? self::frame($node) : null;
        }

        $drop = [];

        foreach ($nodes as $index => $node) {
            if (! is_array($node) || ! self::isField($node)) {
                continue;
            }

            $outer = $frames[$index];

            if ($outer === null || trim((string) ($node['content-desc'] ?? '')) !== '') {
                continue;
            }

            foreach ($nodes as $otherIndex => $other) {
                if ($otherIndex === $index || ! is_array($other) || ! self::isCaption($other)) {
                    continue;
                }

                $label = trim((string) ($other['text'] ?? ''));
                $inner = $frames[$otherIndex];

                if ($label === '' || $label === trim((string) ($node['text'] ?? '')) || $inner === null || ! self::frameInside($inner, $outer)) {
                    continue;
                }

                $nodes[$index]['content-desc'] = $label;
                $drop[$otherIndex] = true;

                break;
            }
        }

        foreach ($nodes as $index => $node) {
            if (! is_array($node) || ! self::truthy($node['checkable'] ?? false) || ! self::truthy($node['checked'] ?? false)) {
                continue;
            }

            if (trim((string) ($node['text'] ?? '')) !== '' || trim((string) ($node['content-desc'] ?? '')) !== '') {
                continue;
            }

            $outer = $frames[$index];

            if ($outer === null) {
                continue;
            }

            foreach ($nodes as $otherIndex => $other) {
                if ($otherIndex === $index || isset($drop[$otherIndex]) || ! is_array($other)) {
                    continue;
                }

                $inner = $frames[$otherIndex];
                $label = trim((string) ($other['content-desc'] ?? $other['text'] ?? ''));

                if ($label === '' || $inner === null || ! self::frameInside($inner, $outer)) {
                    continue;
                }

                $nodes[$otherIndex]['checked'] = 'true';
            }
        }

        $kept = [];

        foreach ($nodes as $index => $node) {
            if (! isset($drop[$index])) {
                $kept[] = $node;
            }
        }

        return $kept;
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function isField(array $node): bool
    {
        $class = strtolower((string) ($node['class'] ?? $node['type'] ?? ''));

        return str_contains($class, 'edittext') || str_contains($class, 'textfield');
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function isCaption(array $node): bool
    {
        $class = strtolower((string) ($node['class'] ?? $node['type'] ?? ''));

        return str_contains($class, 'textview') || str_contains($class, 'statictext');
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $inner
     * @param  array{0: float, 1: float, 2: float, 3: float}  $outer
     */
    private static function frameInside(array $inner, array $outer): bool
    {
        return $inner[0] >= $outer[0] - 1
            && $inner[1] >= $outer[1] - 1
            && ($inner[0] + $inner[2]) <= ($outer[0] + $outer[2] + 1)
            && ($inner[1] + $inner[3]) <= ($outer[1] + $outer[3] + 1);
    }

    /**
     * iOS 26 draws the active tab as a lens over the button, and that lens
     * carries no selected trait of its own. Android draws the same kind of
     * pill as an empty selected view.
     *
     * @param  array<mixed>  $node
     * @param  list<array{0: float, 1: float, 2: float, 3: float}>  $frames
     */
    private static function selectionFrames(array $node, array &$frames): void
    {
        $type = strtolower((string) ($node['type'] ?? $node['role'] ?? $node['AXRole'] ?? $node['class'] ?? ''));

        $frame = self::frame($node);
        $label = trim((string) ($node['AXLabel'] ?? $node['label'] ?? $node['text'] ?? $node['content-desc'] ?? ''));
        $small = $frame !== null && $frame[2] <= 400 && $frame[3] <= 400;

        if ($frame !== null && (str_contains($type, 'tabselection') || ($small && $label === '' && self::truthy($node['selected'] ?? false)))) {
            $frames[] = $frame;
        }

        foreach (['children', 'AXChildren', 'nodes', 'elements'] as $key) {
            if (! isset($node[$key]) || ! is_array($node[$key])) {
                continue;
            }

            foreach ($node[$key] as $child) {
                if (is_array($child)) {
                    self::selectionFrames($child, $frames);
                }
            }
        }
    }

    /**
     * @param  array{0: float, 1: float}|null  $center
     * @param  list<array{0: float, 1: float, 2: float, 3: float}>  $frames
     */
    private static function covered(?array $center, array $frames): bool
    {
        if ($center === null) {
            return false;
        }

        foreach ($frames as [$x, $y, $width, $height]) {
            if ($center[0] >= $x && $center[0] <= $x + $width && $center[1] >= $y && $center[1] <= $y + $height) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<array<mixed>>  $nodes
     */
    private static function walk(array $node, array &$nodes, ?string $chrome = null): void
    {
        $own = self::chrome($node);

        if (self::isSystem($chrome) && ($own === null || $own === 'navigation' || $own === 'tab')) {
            $own = $chrome;
        }

        $chrome = $own ?? $chrome;
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
     * Buttons, fields, switches, images, and web views stay in the tree
     * when they have a frame, so a failure can name one that has no label.
     *
     * @param  array{0: float, 1: float}|null  $center
     */
    private static function interactive(?string $role, ?array $center): bool
    {
        return $center !== null && in_array($role, ['Button', 'TextField', 'Switch', 'Image', 'WebView'], true);
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

        if (is_string($value) && strcasecmp(trim($value), 'checked') === 0) {
            return true;
        }

        return $value === true || $value === 1 || $value === 1.0 || $value === '1' || $value === 'true';
    }

    /**
     * Chrome this node passes to its children. A flat Android dump stores
     * the result on each child as __inherited so a title inside a toolbar
     * still counts as navigation.
     *
     * @param  array<mixed>  $node
     */
    public static function inheritedChrome(array $node, ?string $parent): ?string
    {
        $own = self::chrome($node);

        if (self::isSystem($parent) && ($own === null || $own === 'navigation' || $own === 'tab')) {
            $own = $parent;
        }

        return $own ?? $parent;
    }

    private static function isSystem(?string $chrome): bool
    {
        return $chrome === 'alert' || $chrome === 'share' || $chrome === 'photos';
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function chrome(array $node): ?string
    {
        $system = self::systemChrome($node);

        if ($system !== null) {
            return $system;
        }

        $role = strtolower((string) ($node['type'] ?? $node['role'] ?? $node['AXRole'] ?? $node['class'] ?? ''));

        if (str_contains($role, 'navigationbar') || str_contains($role, 'toolbar') || str_contains($role, 'actionbar')) {
            return 'navigation';
        }

        if (str_contains($role, 'tabbar') || str_contains($role, 'bottomnavigation') || str_contains($role, 'tabwidget')) {
            return 'tab';
        }

        return null;
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function systemChrome(array $node): ?string
    {
        $role = strtolower((string) ($node['type'] ?? $node['role'] ?? $node['AXRole'] ?? $node['class'] ?? ''));
        $id = strtolower((string) ($node['resource-id'] ?? $node['AXUniqueId'] ?? $node['identifier'] ?? ''));
        $package = strtolower((string) ($node['package'] ?? ''));
        $label = strtolower(trim((string) ($node['AXLabel'] ?? $node['label'] ?? $node['text'] ?? $node['content-desc'] ?? '')));
        $sheet = str_contains($role, 'sheet');

        if (
            str_contains($package, 'intentresolver')
            || str_contains($package, 'chooser')
            || str_contains($role, 'chooser')
            || str_contains($role, 'uiactivity')
            || str_contains($role, 'activitylist')
            || str_contains($role, 'activityview')
            || str_contains($id, 'resolver')
            || str_contains($id, 'chooser')
            || ($sheet && ($label === 'share' || $label === 'share via'))
        ) {
            return 'share';
        }

        if (
            str_contains($package, 'providers.media')
            || str_contains($role, 'phpicker')
            || str_contains($role, 'photospicker')
            || (str_contains($role, 'picker') && str_contains($role, 'photo'))
            || ($sheet && ($label === 'photos' || $label === 'recents' || $label === 'photo library'))
        ) {
            return 'photos';
        }

        if (
            str_contains($role, 'alert')
            || str_contains($role, 'actionsheet')
            || str_contains($role, 'action sheet')
            || str_contains($id, 'android:id/button')
            || str_contains($id, 'android:id/alerttitle')
            || str_contains($id, 'android:id/parentpanel')
            || $sheet
        ) {
            return 'alert';
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
     * The display is the frame that starts at the origin and has a phone or
     * tablet aspect ratio. A scroll view also reports the rows below the
     * fold, and a finger dragged to that content is off the glass.
     *
     * @param  list<array{0: float, 1: float, 2: float, 3: float}>  $frames
     * @return array{0: float, 1: float}|null
     */
    private static function display(array $frames): ?array
    {
        $best = null;
        $area = 0.0;

        foreach ($frames as [$x, $y, $width, $height]) {
            if ($x > 1 || $y > 1 || $width < 200 || $height < 200) {
                continue;
            }

            $portrait = $height / $width;
            $landscape = $width / $height;
            $phone = ($portrait >= 1.2 && $portrait <= 2.8) || ($landscape >= 1.2 && $landscape <= 2.8);

            if (! $phone) {
                continue;
            }

            $frameArea = $width * $height;

            if ($frameArea > $area) {
                $area = $frameArea;
                $best = [$x + $width, $y + $height];
            }
        }

        return $best;
    }

    /**
     * @param  array<mixed>|list<mixed>  $node
     * @param  list<array{0: float, 1: float, 2: float, 3: float}>  $frames
     */
    private static function collectFrames(array $node, array &$frames): void
    {
        if (array_is_list($node)) {
            foreach ($node as $child) {
                if (is_array($child)) {
                    self::collectFrames($child, $frames);
                }
            }

            return;
        }

        $frame = self::frame($node);

        if ($frame !== null) {
            $frames[] = $frame;
        }

        foreach (['children', 'AXChildren', 'nodes', 'elements'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                self::collectFrames($node[$key], $frames);
            }
        }
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function cap(float $width, float $height): array
    {
        if ($width >= 200 && $height > $width * 2.2) {
            $height = $width * 2.2;
        }

        return [$width, $height];
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
     * @param  callable(array<mixed>): bool  $matches
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private static function findFrame(array $node, callable $matches): ?array
    {
        if ($matches($node)) {
            $frame = self::frame($node);

            if ($frame !== null) {
                return $frame;
            }
        }

        foreach (['children', 'AXChildren', 'nodes', 'elements'] as $key) {
            if (! isset($node[$key]) || ! is_array($node[$key])) {
                continue;
            }

            foreach ($node[$key] as $child) {
                if (! is_array($child)) {
                    continue;
                }

                $found = self::findFrame($child, $matches);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
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

        if (str_contains($role, 'Image')) {
            return 'Image';
        }

        return $role;
    }
}
