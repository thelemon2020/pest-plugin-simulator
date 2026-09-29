<?php

declare(strict_types=1);

use NativePhp\Simulator\Gesture;
use NativePhp\Simulator\Screen;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeDriver;

function control(string $label, array $extra = []): array
{
    return array_merge([
        'label' => $label,
        'role' => 'Button',
        'id' => null,
        'center' => [100.0, 200.0],
    ], $extra);
}

it('scrolls, swipes, and goes back', function () {
    $driver = new FakeDriver([[
        control('Note', ['center' => [40.0, 300.0]]),
    ]]);
    $screen = new Screen($driver, timeoutSeconds: 0);

    $screen->scroll('down')->swipe('down')->swipe('left', 'Note')->goBack();

    expect($driver->swipes[0])->toBe(Gesture::scroll('down', 390, 844))
        ->and($driver->swipes[1])->toBe(Gesture::swipe('down', 390, 844))
        ->and($driver->swipes[2])->toBe(Gesture::swipe('left', 390, 844, 40, 300))
        ->and($driver->backs)->toBe(1);
});

it('replaces a field and reads its value', function () {
    $driver = new FakeDriver([[
        control('Title', ['role' => 'TextField', 'value' => 'old', 'center' => [20.0, 30.0]]),
    ], [
        control('Title', ['role' => 'TextField', 'value' => "Ada's note", 'center' => [20.0, 30.0]]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))
        ->type('Title', "Ada's note")
        ->assertValue('Title', "Ada's note");

    expect($driver->clears)->toBe(1)
        ->and($driver->cleared)->toBe(40)
        ->and($driver->texts)->toBe(["Ada's note"]);
});

it('clears a long field before replacing it', function () {
    $value = str_repeat('n', 80);
    $driver = new FakeDriver([[
        control('Title', ['role' => 'TextField', 'value' => $value, 'center' => [20.0, 30.0]]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->type('Title', 'short');

    expect($driver->cleared)->toBe(80);
});

it('writes a screenshot of the current screen', function () {
    $path = sys_get_temp_dir().'/simulator-shot-'.uniqid('', true).'/screen.png';
    $driver = new FakeDriver([[control('Save')]]);

    try {
        (new Screen($driver, timeoutSeconds: 0))->screenshot($path);

        expect(file_get_contents($path))->toBe('png');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }

        $directory = dirname($path);

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('saves the screen when a label matches more than one control', function () {
    $directory = sys_get_temp_dir().'/simulator-ambiguous-'.uniqid('', true);
    $driver = new FakeDriver([[
        control('Save', ['center' => [10.0, 20.0]]),
        control('Save', ['center' => [30.0, 40.0]]),
    ]]);

    try {
        expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: $directory))->tap('Save'))
            ->toThrow(AssertionFailedError::class, 'matches more than one Button');

        expect(is_file($directory.'/tree.json'))->toBeTrue()
            ->and(is_file($directory.'/screen.png'))->toBeTrue();
    } finally {
        if (is_dir($directory)) {
            array_map('unlink', glob($directory.'/*') ?: []);
            rmdir($directory);
        }
    }
});

it('reads chrome, tabs, and control state', function () {
    $driver = new FakeDriver([[
        control('Notes', ['chrome' => 'navigation', 'center' => null]),
        control('Home', ['selected' => true, 'chrome' => 'tab', 'id' => '/notes']),
        control('Flashlight', ['role' => 'Switch', 'checked' => true]),
        control('Save', ['enabled' => false]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))
        ->assertNavTitle('Notes')
        ->assertTabActive('Home')
        ->assertNavigatedTo('/notes')
        ->assertNavigatedTo('/media/notes')
        ->assertChecked('Flashlight')
        ->assertDisabled('Save')
        ->assertEnabled('Home');
});

it('names an unlabeled control when a tap misses', function () {
    $driver = new FakeDriver([[
        control('Home'),
        control('', ['id' => 'save-button', 'center' => [8.0, 9.0]]),
    ]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: sys_get_temp_dir().'/simulator-unlabeled-test'))
        ->tap('Save'))
        ->toThrow(AssertionFailedError::class, 'These controls have no accessibility label: Button [save-button].');
});

it('taps a control by its accessibility id', function () {
    $driver = new FakeDriver([[
        control('', ['id' => 'save-button', 'center' => [8.0, 9.0]]),
    ]]);

    (new Screen($driver, timeoutSeconds: 0))->tap('save-button');

    expect($driver->taps)->toBe([[8.0, 9.0]]);
});

it('says when the screen is a web view', function () {
    $driver = new FakeDriver([[
        control('Page', ['role' => 'WebView', 'webview' => true]),
    ]]);

    expect(fn () => (new Screen($driver, timeoutSeconds: 0, failureDirectory: sys_get_temp_dir().'/simulator-webview-test'))
        ->assertSee('Welcome'))
        ->toThrow(AssertionFailedError::class, 'WebView');
});
