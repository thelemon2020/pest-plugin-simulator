<?php

declare(strict_types=1);

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
