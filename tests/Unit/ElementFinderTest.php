<?php

declare(strict_types=1);

use NativePhp\Simulator\AccessibilityTree;
use NativePhp\Simulator\ElementFinder;

$button = fn (string $label, ?string $id = null, array $center = [10, 10], string $role = 'Button'): array => [
    'label' => $label,
    'role' => $role,
    'id' => $id,
    'center' => $center,
];

it('taps an accessibility id when the control has no label', function () use ($button) {
    $match = (new ElementFinder)->match([
        $button('', 'save-button', [8, 9]),
    ], 'save-button');

    expect($match['center'])->toBe([8, 9]);
});

it('names unlabeled controls in the failure description', function () use ($button) {
    $description = (new ElementFinder)->describe([
        $button('Home'),
        $button('', 'save-button', [8, 9]),
        $button('', null, [1, 2], 'Image'),
    ]);

    expect($description)
        ->toContain('Button: Home')
        ->toContain('Button: [save-button]')
        ->toContain('Image: (no label)')
        ->toContain('These controls have no accessibility label: Button [save-button], Image.');
});

it('prefers an accessibility identifier over a label', function () use ($button) {
    $match = (new ElementFinder)->match([
        $button('Something else', 'vibrate-card', [1, 2]),
        $button('vibrate-card', null, [3, 4]),
    ], 'vibrate-card');

    expect($match['center'])->toBe([1, 2]);
});

it('matches a button label exactly', function () use ($button) {
    $match = (new ElementFinder)->match([
        $button('Discover', null, [129.75, 822.0]),
        $button('Playing'),
    ], 'Discover');

    expect($match['label'])->toBe('Discover');
});

it('prefers the button when a label is also on static text', function () use ($button) {
    $match = (new ElementFinder)->match([
        $button('Discover', null, [1, 1], 'StaticText'),
        $button('Discover', null, [9, 9]),
    ], 'Discover');

    expect($match['center'])->toBe([9, 9])
        ->and($match['role'])->toBe('Button');
});

it('matches a label that contains the text when nothing is exact', function () use ($button) {
    $match = (new ElementFinder)->match([
        $button('Turn the lights on'),
    ], 'lights on');

    expect($match['label'])->toBe('Turn the lights on');
});

it('types into the field when its label is also static text', function () use ($button) {
    $match = (new ElementFinder)->match([
        $button('Email', null, [49, 344], 'StaticText'),
        $button('Email', null, [201, 380], 'TextField'),
    ], 'Email');

    expect($match['role'])->toBe('TextField')
        ->and($match['center'])->toBe([201, 380]);
});

it('taps the switch when its label is also static text', function () use ($button) {
    $match = (new ElementFinder)->match([
        $button('Run this schedule', null, [1, 1], 'StaticText'),
        $button('Run this schedule', null, [9, 9], 'Switch'),
    ], 'Run this schedule');

    expect($match['role'])->toBe('Switch')
        ->and($match['center'])->toBe([9, 9]);
});

it('types into a multiline field when its floating label is also static text', function () use ($button) {
    $match = (new ElementFinder)->match([
        $button('Note', null, [1, 1], 'StaticText'),
        $button('Note', null, [9, 9], 'TextView'),
    ], 'Note');

    expect($match['role'])->toBe('TextView')
        ->and($match['center'])->toBe([9, 9]);
});

it('prefers plain content over a nav title mirroring the same label', function () {
    $match = (new ElementFinder)->match([
        ['label' => 'Top shelf', 'role' => 'StaticText', 'id' => null, 'center' => [201, 84], 'chrome' => 'navigation'],
        ['label' => 'Top shelf', 'role' => 'StaticText', 'id' => null, 'center' => [156, 156], 'chrome' => null],
    ], 'Top shelf');

    expect($match['center'])->toBe([156, 156]);
});

it('does not let a nav title EXACT match beat real content that only CONTAINS the target', function () use ($button) {
    // The three-tier search in match() (id, exact, contains) used to return as soon as
    // ANY tier had a match — so a nav bar title that exactly equals the target short-
    // circuited past a real control one tier down whose longer a11y-label only contains
    // it, before choose()'s own chrome-vs-content preference ever got a chance to run
    // (that only resolves ties WITHIN one tier, not across tiers). Reproduces
    // SegmentEditor's rename button: nav title "Top shelf" (exact) vs. the button's own
    // label "Rename or recolour Top shelf" (contains) — real hardware confirmed the nav
    // title was winning and the button never actually received the tap.
    $match = (new ElementFinder)->match([
        ['label' => 'Top shelf', 'role' => 'StaticText', 'id' => null, 'center' => [201, 84], 'chrome' => 'navigation'],
        $button('Rename or recolour Top shelf', null, [61, 154]),
    ], 'Top shelf');

    expect($match['center'])->toBe([61, 154])
        ->and($match['role'])->toBe('Button');
});

it('still matches a real Button or interactive control living in nav/tab chrome', function () use ($button) {
    // The chrome exclusion in match() must not blind it to a REAL interactive control
    // just because that control happens to live inside a nav bar or tab bar — a Back
    // button, a toolbar action, or a tab item are all legitimate tap() targets.
    $match = (new ElementFinder)->match([
        ['label' => 'Back', 'role' => 'Button', 'id' => null, 'center' => [40, 84], 'chrome' => 'navigation'],
    ], 'Back');

    expect($match['center'])->toBe([40, 84]);
});

it('refuses to guess between two buttons with the same label', function () use ($button) {
    (new ElementFinder)->match([
        $button('Shelf'),
        $button('Shelf', null, [20, 20]),
    ], 'Shelf');
})->throws(NativePhp\Simulator\Exceptions\AmbiguousMatch::class);

it('finds the Open button only while the system dialog is up', function () use ($button) {
    $finder = new ElementFinder;

    $dialog = $finder->openDialogButton([
        $button('Open in “CollectShine”?', null, [200, 400], 'StaticText'),
        $button('Open', null, [275, 474]),
        $button('Cancel', null, [120, 474]),
    ]);

    expect($dialog['center'])->toBe([275, 474])
        ->and($finder->openDialogButton([$button('Open')]))->toBeNull();
});

it('finds a field named only by its placeholder where it was tapped, once text replaces the placeholder', function () {
    // Captured from CollectShine's collection screen on an iOS 26.5 Simulator. The search
    // field has no label or identifier; iOS reports its placeholder as the value while it
    // is empty, and the typed text once it is not.
    $fixtures = dirname(__DIR__).'/Fixtures';
    $empty = AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-unlabeled-search-field-empty.json'));
    $typed = AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-unlabeled-search-field-typed.json'));
    $finder = new ElementFinder;

    $field = $finder->match($empty, 'Search artist or title…');

    expect($field['role'])->toBe('TextField')
        ->and($finder->sees($typed, 'Search artist or title…'))->toBeFalse()
        ->and($finder->holds($typed, 'Search artist or title…', $field, 'Talk'))->toBeTrue()
        ->and($finder->holds($typed, 'Search artist or title…', $field, 'Tal'))->toBeFalse()
        ->and($finder->holds($empty, 'Search artist or title…', $field, 'Talk'))->toBeFalse();
});

it('confirms a secure field by one bullet per typed character', function () {
    // Captured from CollectShine's sign-in screen on an iOS 26.5 Simulator. iOS reports a
    // secure field's text masked, until "Show password" turns it into a plain field.
    $fixtures = dirname(__DIR__).'/Fixtures';
    $empty = AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-secure-field-empty.json'));
    $typed = AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-secure-field-typed.json'));
    $revealed = AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-secure-field-revealed.json'));
    $finder = new ElementFinder;

    $field = $finder->match($empty, 'Password');

    expect($field['secure'])->toBeTrue()
        ->and($finder->valueOf($typed, 'Password'))->toBe('••••••')
        ->and($finder->holds($typed, 'Password', $field, 'secret'))->toBeTrue()
        ->and($finder->holds($typed, 'Password', $field, 'secre'))->toBeFalse()
        ->and($finder->holds($empty, 'Password', $field, 'secret'))->toBeFalse()
        ->and($finder->valueOf($revealed, 'Password'))->toBe('secret')
        ->and($finder->holds($revealed, 'Password', $field, 'secret'))->toBeTrue()
        ->and($finder->holds($revealed, 'Password', $field, 'secrets'))->toBeFalse();
});

it('compares a field that is not secure as typed, even against bullets', function () use ($button) {
    $field = $button('Code', null, [200, 300], 'TextField') + ['value' => '••••••'];

    expect((new ElementFinder)->holds([$field], 'Code', $field, 'secret'))->toBeFalse();
});

it('finds an editor named by the placeholder drawn over it, once text replaces the placeholder', function () {
    // Captured from CollectShine's chat composer on an iOS 26.5 Simulator. The editor has
    // no label, and its placeholder is a separate caption laid over it.
    $fixtures = dirname(__DIR__).'/Fixtures';
    $empty = AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-placeholder-editor-empty.json'));
    $typed = AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-placeholder-editor-typed.json'));
    $finder = new ElementFinder;

    $field = $finder->match($empty, 'What should I play tonight?');

    expect($field['role'])->toBe('TextView')
        ->and($finder->sees($typed, 'What should I play tonight?'))->toBeFalse()
        ->and($finder->holds($typed, 'What should I play tonight?', $field, 'What should I play?'))->toBeTrue()
        ->and($finder->holds($typed, 'What should I play tonight?', $field, 'What should I play'))->toBeFalse()
        ->and($finder->holds($empty, 'What should I play tonight?', $field, 'What should I play?'))->toBeFalse();
});

it('reads the field nearest where it was tapped, not any field holding the text', function () use ($button) {
    $field = $button('Password', null, [200, 300], 'TextField');

    expect((new ElementFinder)->holds([
        $button('secret', null, [200, 200], 'TextField'),
        $button('sec', null, [200, 302], 'TextField'),
    ], 'Password', $field, 'secret'))->toBeFalse();
});

it('does not look for a field where a control that is not one was tapped', function () use ($button) {
    $control = $button('Search', null, [200, 300], 'StaticText');

    expect((new ElementFinder)->holds([
        $button('Talk', null, [200, 300], 'TextField'),
    ], 'Search', $control, 'Talk'))->toBeFalse();
});

it('reads a Compose chip that is on as selected and checked, by its text', function () {
    // Captured from CollectShine's mood sheet on an android-36 emulator, with "😌 Chill"
    // on. Compose puts the chip's state on an unlabeled container and its text on a
    // TextView inside it, and reports a selected chip as checked.
    $elements = AccessibilityTree::summarize((string) file_get_contents(dirname(__DIR__).'/Fixtures/android-mood-chips.json'));
    $finder = new ElementFinder;

    expect($finder->isSelected($elements, '😌 Chill', true))->toBeTrue()
        ->and($finder->isChecked($elements, '😌 Chill', true))->toBeTrue()
        ->and($finder->isSelected($elements, '🔥 Hyped', false))->toBeTrue()
        ->and($finder->isSelected($elements, 'How was it?', false))->toBeTrue();
});

it('reads a Compose switch by the label beside it in its row', function () {
    // Captured from CollectShine's schedule screen on an android-36 emulator, with "Run
    // this schedule" on. The switch is an unlabeled checkable view, level with its label
    // and subtitle in the same row.
    $elements = AccessibilityTree::summarize((string) file_get_contents(dirname(__DIR__).'/Fixtures/android-schedule-switch.json'));
    $finder = new ElementFinder;

    expect($finder->isChecked($elements, 'Run this schedule', true))->toBeTrue()
        ->and($finder->isChecked($elements, 'Your base station follows it', true))->toBeTrue()
        ->and($finder->isChecked($elements, 'Mon', false))->toBeTrue()
        ->and($finder->isChecked($elements, "All times are America/New_York, your base station's clock.", false))->toBeTrue();
});

it('does not share a switch with a label in a row that has another checkable control', function () {
    $node = fn (string $class, string $bounds, string $text = '', bool $checkable = false, bool $checked = false): array => [
        'text' => $text, 'content-desc' => '', 'resource-id' => '', 'class' => $class, 'package' => 'com.example',
        'bounds' => $bounds, 'checked' => $checked ? 'true' : 'false', 'checkable' => $checkable ? 'true' : 'false',
        'enabled' => 'true', 'selected' => 'false',
    ];
    $elements = AccessibilityTree::summarize((string) json_encode([
        $node('android.view.View', '[0,0][1080,2400]'),
        $node('android.view.View', '[42,346][1038,700]'),
        $node('android.widget.TextView', '[84,383][448,446]', 'Wi-Fi'),
        $node('android.view.View', '[859,378][996,504]', checkable: true, checked: true),
        $node('android.view.View', '[859,560][996,686]', checkable: true),
    ]));

    expect((new ElementFinder)->isChecked($elements, 'Wi-Fi', false))->toBeTrue();
});

it('names a Compose button by the text drawn inside it', function () {
    // Captured from CollectShine's Identify screen on an android-36 emulator. The button
    // is an unlabeled Button with its text on a TextView of its own, and the screen title
    // in the top bar says "Identify" too.
    $elements = AccessibilityTree::summarize((string) file_get_contents(dirname(__DIR__).'/Fixtures/android-identify-button.json'));

    $match = (new ElementFinder)->match($elements, 'Identify');

    expect($match['role'])->toBe('Button')
        ->and($match['center'])->toBe([540.0, 1443.5])
        ->and((new ElementFinder)->match($elements, 'arrow_back')['role'])->toBe('Button');
});
