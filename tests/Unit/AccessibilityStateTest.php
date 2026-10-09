<?php

declare(strict_types=1);

use NativePhp\Simulator\AccessibilityTree;

it('ignores a software-keyboard key parked below the display', function () {
    $json = (string) json_encode([
        'elements' => [[
            'type' => 'UIInputSetHostView',
            'frame' => ['x' => 0, 'y' => 874, 'width' => 402, 'height' => 270],
            'children' => [[
                'type' => 'Key',
                'label' => '@',
                'frame' => ['x' => 200, 'y' => 1087, 'width' => 50, 'height' => 54],
            ]],
        ]],
    ]);

    expect(AccessibilityTree::keyPoint($json, '@', 402, 874))->toBeNull()
        ->and(AccessibilityTree::keyPoint($json, '.', 402, 874))->toBeNull();
});

it('taps a software-keyboard key that is on the display', function () {
    $json = (string) json_encode([
        'elements' => [[
            'type' => 'Key',
            'label' => '@',
            'frame' => ['x' => 200, 'y' => 700, 'width' => 50, 'height' => 54],
        ]],
    ]);

    expect(AccessibilityTree::keyPoint($json, '@', 402, 874))->toBe([225.0, 727.0]);
});

it('keeps a field label apart from its value', function () {
    $rows = AccessibilityTree::summarize((string) json_encode([
        [
            'content-desc' => 'Email',
            'text' => 'ada@example.com',
            'class' => 'android.widget.EditText',
            'bounds' => '[10,20][110,60]',
            'enabled' => 'false',
        ],
    ]));

    expect($rows[0]['label'])->toBe('Email')
        ->and($rows[0]['role'])->toBe('TextField')
        ->and($rows[0]['value'])->toBe('ada@example.com')
        ->and($rows[0]['enabled'])->toBeFalse();
});

it('reads a checked switch and a selected tab', function () {
    $rows = AccessibilityTree::summarize((string) json_encode([
        'role' => 'TabBar',
        'AXFrame' => '{{0, 700}, {390, 80}}',
        'children' => [
            [
                'AXLabel' => 'Home',
                'role' => 'Button',
                'AXFrame' => '{{20, 720}, {80, 40}}',
                'traits' => ['Button', 'Selected'],
            ],
            [
                'AXLabel' => 'Flashlight',
                'role' => 'Switch',
                'AXValue' => '1',
                'AXFrame' => '{{20, 200}, {80, 40}}',
            ],
        ],
    ]));

    expect($rows[0]['label'])->toBe('Home')
        ->and($rows[0]['chrome'])->toBe('tab')
        ->and($rows[0]['selected'])->toBeTrue()
        ->and($rows[1]['role'])->toBe('Switch')
        ->and($rows[1]['checked'])->toBeTrue()
        ->and($rows[1]['chrome'])->toBe('tab');
});

it('reads a compose outlined field, checkbox, and tab pill', function () {
    $rows = AccessibilityTree::summarize((string) json_encode([
        [
            'class' => 'android.widget.EditText',
            'text' => 'Ada@example.com',
            'content-desc' => '',
            'bounds' => '[84,466][996,634]',
            'enabled' => 'true',
        ],
        [
            'class' => 'android.widget.TextView',
            'text' => 'Email',
            'bounds' => '[126,466][209,508]',
        ],
        [
            'class' => 'android.view.View',
            'text' => '',
            'checkable' => 'true',
            'checked' => 'true',
            'bounds' => '[84,855][488,981]',
        ],
        [
            'class' => 'android.view.View',
            'content-desc' => 'Show the password you typed',
            'checked' => 'false',
            'bounds' => '[84,886][488,949]',
        ],
        [
            'class' => 'android.view.View',
            'text' => '',
            'selected' => 'true',
            'bounds' => '[881,2127][1080,2337]',
        ],
        [
            'class' => 'android.widget.TextView',
            'text' => 'More',
            'selected' => 'false',
            'bounds' => '[941,2253][1019,2295]',
        ],
        [
            'class' => 'android.widget.TextView',
            'text' => 'Playing',
            'selected' => 'false',
            'bounds' => '[45,2253][155,2295]',
        ],
    ]));

    $byLabel = [];

    foreach ($rows as $row) {
        $byLabel[$row['label']] = $row;
    }

    expect($byLabel['Email']['role'])->toBe('TextField')
        ->and($byLabel['Email']['value'])->toBe('Ada@example.com')
        ->and($byLabel)->not->toHaveKey('Ada@example.com')
        ->and($byLabel['Show the password you typed']['checked'])->toBeTrue()
        ->and($byLabel['More']['selected'])->toBeTrue()
        ->and($byLabel['Playing']['selected'])->toBeFalse();
});

it('presses a bottom tab above the gesture bar', function () {
    $rows = AccessibilityTree::summarize((string) json_encode([
        [
            'class' => 'android.widget.FrameLayout',
            'bounds' => '[0,0][1080,2400]',
        ],
        [
            'class' => 'android.view.View',
            'bounds' => '[441,2127][640,2337]',
        ],
        [
            'class' => 'android.widget.TextView',
            'text' => 'Collection',
            'bounds' => '[461,2253][619,2295]',
        ],
    ]));

    expect($rows[0]['label'])->toBe('Collection')
        ->and($rows[0]['center'])->toBe([540.5, 2232.0]);
});

it('reads an iOS switch value and the tab lens', function () {
    $rows = AccessibilityTree::summarize((string) json_encode([
        'type' => 'TabBar',
        'label' => 'Tab Bar',
        'frame' => ['x' => 0, 'y' => 791, 'width' => 402, 'height' => 83],
        'children' => [
            [
                'type' => 'Button',
                'label' => 'More',
                'frame' => ['x' => 303, 'y' => 795, 'width' => 74, 'height' => 54],
            ],
            [
                'type' => 'Button',
                'label' => 'Playing',
                'frame' => ['x' => 25, 'y' => 795, 'width' => 74, 'height' => 54],
            ],
            [
                'type' => '_UITabSelectionView',
                'frame' => ['x' => 303, 'y' => 795, 'width' => 74, 'height' => 54],
            ],
        ],
    ]));

    $byLabel = [];

    foreach ($rows as $row) {
        $byLabel[$row['label']] = $row;
    }

    expect($byLabel['More']['selected'])->toBeTrue()
        ->and($byLabel['Playing']['selected'])->toBeFalse();

    $unchecked = AccessibilityTree::summarize((string) json_encode([
        ['type' => 'Switch', 'label' => 'Show password', 'value' => 'Unchecked', 'frame' => ['x' => 20, 'y' => 500, 'width' => 180, 'height' => 44]],
    ]));
    $checked = AccessibilityTree::summarize((string) json_encode([
        ['type' => 'Switch', 'label' => 'Show password', 'value' => 'Checked', 'frame' => ['x' => 20, 'y' => 500, 'width' => 180, 'height' => 44]],
    ]));

    expect($unchecked[0]['checked'])->toBeFalse()
        ->and($checked[0]['checked'])->toBeTrue();
});

it('marks navigation chrome and the screen size', function () {
    $json = (string) json_encode([
        'role' => 'NavigationBar',
        'AXLabel' => 'Notes',
        'AXFrame' => '{{0, 0}, {390, 844}}',
        'children' => [
            ['AXLabel' => 'Notes', 'role' => 'StaticText', 'AXFrame' => '{{120, 50}, {150, 22}}'],
        ],
    ]);

    $rows = AccessibilityTree::summarize($json);

    expect($rows[0]['chrome'])->toBe('navigation')
        ->and($rows[1]['chrome'])->toBe('navigation')
        ->and(AccessibilityTree::viewport($json))->toBe([390.0, 844.0]);
});

it('keeps the display when a scrolled page is taller than the glass', function () {
    $json = (string) json_encode([
        'role' => 'Application',
        'AXFrame' => '{{0, 0}, {402, 874}}',
        'children' => [
            ['AXLabel' => 'Tasks and a table', 'role' => 'StaticText', 'AXFrame' => '{{20, 1800}, {300, 40}}'],
        ],
    ]);

    expect(AccessibilityTree::viewport($json))->toBe([402.0, 874.0]);
});

it('keeps a control that only has an accessibility id', function () {
    $rows = AccessibilityTree::summarize((string) json_encode([
        ['type' => 'Button', 'AXUniqueId' => 'save-button', 'AXFrame' => '{{10, 20}, {40, 40}}'],
        ['class' => 'android.widget.ImageButton', 'resource-id' => 'com.example.app:id/add', 'text' => '', 'bounds' => '[10,20][50,60]'],
    ]));

    expect($rows[0]['label'])->toBe('')
        ->and($rows[0]['id'])->toBe('save-button')
        ->and($rows[0]['role'])->toBe('Button')
        ->and($rows[1]['id'])->toBe('com.example.app:id/add')
        ->and($rows[1]['role'])->toBe('Button');
});

it('keeps an unlabeled button and drops an unlabeled group', function () {
    $rows = AccessibilityTree::summarize((string) json_encode([
        ['type' => 'Button', 'AXFrame' => '{{10, 20}, {40, 40}}'],
        ['type' => 'Group', 'AXFrame' => '{{0, 0}, {200, 200}}'],
    ]));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['role'])->toBe('Button')
        ->and($rows[0]['label'])->toBe('');
});

it('recognizes a web view', function () {
    $rows = AccessibilityTree::summarize((string) json_encode([
        ['role' => 'WebView', 'AXLabel' => 'Page', 'AXFrame' => '{{0, 0}, {390, 844}}'],
    ]));

    expect($rows[0]['role'])->toBe('WebView')
        ->and($rows[0]['webview'])->toBeTrue();
});

/**
 * The Schedule screen as AXBRIDGE read it on an iPhone 17 Pro, trimmed: a vertical page
 * ScrollView holding a row of day chips in its own horizontal ScrollView, Sun past the edge.
 *
 * @param  list<array<string, mixed>>  $indicators
 */
function scheduleTree(array $indicators): string
{
    $frame = fn (float $x, float $y, float $width, float $height): array => ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
    $days = [['Mon', 16, 60], ['Tue', 84, 55], ['Wed', 148, 62], ['Sun', 399, 56]];

    return (string) json_encode(['elements' => [[
        'type' => 'ScrollView',
        'frame' => $frame(0, 0, 402, 874),
        'children' => [
            [
                'type' => 'ScrollView',
                'frame' => $frame(16, 285, 370, 42),
                'children' => [
                    [
                        'type' => 'PlatformGroupContainer',
                        'frame' => $frame(16, 285, 440, 42),
                        'children' => array_map(fn (array $day): array => [
                            'type' => 'StaticText',
                            'label' => $day[0],
                            'frame' => $frame($day[1], 285, $day[2], 42),
                        ], $days),
                    ],
                    ...$indicators,
                ],
            ],
            ['type' => 'Button', 'label' => 'Add a window', 'frame' => $frame(137, 417, 128, 35)],
            ['type' => '_UIScrollViewScrollIndicator', 'label' => 'Vertical scroll bar, 1 page', 'frame' => $frame(369, 116, 30, 696)],
        ],
    ]]]);
}

it('marks a control inside a row that scrolls sideways', function () {
    $rows = AccessibilityTree::summarize(scheduleTree([
        ['type' => '_UIScrollViewScrollIndicator', 'label' => 'Horizontal scroll bar, 2 pages', 'frame' => ['x' => 16, 'y' => 303, 'width' => 370, 'height' => 21]],
    ]));
    $byLabel = array_column($rows, null, 'label');

    expect($byLabel['Sun']['carousel'])->toBe([16.0, 285.0, 370.0, 42.0])
        ->and($byLabel['Mon']['carousel'])->toBe([16.0, 285.0, 370.0, 42.0])
        ->and($byLabel['Add a window']['carousel'])->toBeNull();
});

it('marks a row that scrolls sideways from its content when it has no scroll bar', function () {
    $byLabel = array_column(AccessibilityTree::summarize(scheduleTree([])), null, 'label');

    // The chips reach x 456 past their own ScrollView, but that ScrollView clips them, so
    // the page around it still only scrolls up and down.
    expect($byLabel['Sun']['carousel'])->toBe([16.0, 285.0, 370.0, 42.0])
        ->and($byLabel['Add a window']['carousel'])->toBeNull();
});

it('marks nothing as a carousel on a page that only scrolls up and down', function () {
    $rows = AccessibilityTree::summarize((string) json_encode(['elements' => [[
        'type' => 'ScrollView',
        'frame' => ['x' => 0, 'y' => 0, 'width' => 402, 'height' => 874],
        'children' => [
            ['type' => 'Button', 'label' => 'Row 1', 'frame' => ['x' => 0, 'y' => 100, 'width' => 402, 'height' => 44]],
            ['type' => 'Button', 'label' => 'Row 40', 'frame' => ['x' => 0, 'y' => 1900, 'width' => 402, 'height' => 44]],
        ],
    ]]]));

    expect(array_column($rows, 'carousel', 'label'))->toBe(['Row 1' => null, 'Row 40' => null]);
});

it('marks a secure field', function () {
    $rows = AccessibilityTree::summarize((string) json_encode([
        ['type' => 'TextField', 'label' => 'Email', 'value' => 'ada@example.com', 'traits' => ['TextEntry'], 'frame' => ['x' => 44, 'y' => 369, 'width' => 314, 'height' => 22]],
        ['type' => 'SecureTextField', 'label' => 'Password', 'value' => '••••••', 'traits' => ['SecureTextField', 'TextEntry'], 'frame' => ['x' => 44, 'y' => 451, 'width' => 314, 'height' => 21]],
    ]));

    expect($rows[0]['secure'])->toBeFalse()
        ->and($rows[1]['role'])->toBe('TextField')
        ->and($rows[1]['secure'])->toBeTrue();
});

it('names an empty unlabeled editor by the placeholder drawn inside it', function () {
    // CollectShine's chat composer: the editor reports no label or value until text goes
    // in, and the placeholder is a sibling caption laid over it.
    $rows = AccessibilityTree::summarize((string) json_encode([
        ['type' => 'TextView', 'label' => null, 'value' => null, 'frame' => ['x' => 23, 'y' => 737, 'width' => 356, 'height' => 37]],
        ['type' => 'StaticText', 'label' => 'What should I play tonight?', 'frame' => ['x' => 28, 'y' => 745, 'width' => 202, 'height' => 21]],
        ['type' => 'StaticText', 'label' => 'Ask about your own records.', 'frame' => ['x' => 16, 'y' => 132, 'width' => 334, 'height' => 62]],
    ]));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['label'])->toBe('What should I play tonight?')
        ->and($rows[0]['role'])->toBe('TextView')
        ->and($rows[0]['center'])->toBe([201.0, 755.5])
        ->and($rows[1]['label'])->toBe('Ask about your own records.');
});

it('leaves the caption alone once the editor holds text', function () {
    $rows = AccessibilityTree::summarize((string) json_encode([
        ['type' => 'TextView', 'label' => null, 'value' => 'What should I play?', 'frame' => ['x' => 23, 'y' => 737, 'width' => 356, 'height' => 37]],
        ['type' => 'StaticText', 'label' => 'What should I play? ', 'frame' => ['x' => 28, 'y' => 745, 'width' => 149, 'height' => 21]],
    ]));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['label'])->toBe('What should I play?')
        ->and($rows[0]['role'])->toBe('TextView')
        ->and($rows[1]['role'])->toBe('StaticText');
});
