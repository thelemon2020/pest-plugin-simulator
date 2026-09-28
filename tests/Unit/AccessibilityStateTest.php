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

it('recognizes a web view', function () {
    $rows = AccessibilityTree::summarize((string) json_encode([
        ['role' => 'WebView', 'AXLabel' => 'Page', 'AXFrame' => '{{0, 0}, {390, 844}}'],
    ]));

    expect($rows[0]['role'])->toBe('WebView')
        ->and($rows[0]['webview'])->toBeTrue();
});
