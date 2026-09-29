<?php

declare(strict_types=1);

use NativePhp\Simulator\AccessibilityTree;

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
