<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidText;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Gesture;
use NativePhp\Simulator\Hid;
use NativePhp\Simulator\IosText;

it('scrolls down by dragging a finger up', function () {
    expect(Gesture::scroll('down', 390, 844))->toBe([195.0, 633.0, 195.0, 211.0])
        ->and(Gesture::scroll('up', 390, 844))->toBe([195.0, 211.0, 195.0, 633.0]);
});

it('swipes in the direction of the finger', function () {
    $down = Gesture::swipe('down', 390, 844);

    expect($down[0])->toBe(195.0)
        ->and($down[1])->toBe(211.0)
        ->and($down[3])->toEqualWithDelta(675.2, 0.001)
        ->and(Gesture::swipe('left', 200, 400, 100, 80))->toBe([100.0, 80.0, 10.0, 80.0]);
});

it('scrolls a shorter distance when asked', function () {
    expect(Gesture::scroll('down', 390, 844, 0.25))->toBe([195.0, 633.0, 195.0, 422.0]);
});

it('swipes a given fraction of the screen', function () {
    $down = Gesture::swipe('down', 390, 844, distance: 0.3);
    $fromRow = Gesture::swipe('left', 390, 844, 200, 400, 0.2);

    expect($down[1])->toBe(211.0)
        ->and($down[3])->toEqualWithDelta(464.2, 0.001)
        ->and($fromRow)->toBe([200.0, 400.0, 122.0, 400.0]);
});

it('refuses a distance that is not a fraction of the screen', function () {
    Gesture::swipe('down', 390, 844, distance: 1.5);
})->throws(SimulatorException::class, 'Distance [1.5] is not between 0 and 1.');

it('swipes in from the left edge to go back', function () {
    expect(Gesture::back(390, 844))->toBe([1.0, 422.0, 273.0, 422.0]);
});

it('refuses an unknown direction', function () {
    Gesture::scroll('left', 390, 844);
})->throws(SimulatorException::class);

it('types punctuation and a newline', function () {
    expect(Hid::text("a+b:c'd\n"))->toHaveCount(20);
});

it('encodes a swipe as an hid event', function () {
    $event = Hid::swipe(1, 2, 3, 4)[0];

    expect($event[0])->toBe("\x12");
});

it('selects all, pastes, and deletes', function () {
    expect(Hid::selectAll())->toHaveCount(4)
        ->and(Hid::paste())->toHaveCount(4)
        ->and(Hid::backspace())->toHaveCount(2);
});

it('pastes an email and types a password', function () {
    expect(IosText::paste('Ada@example.com'))->toBeTrue()
        ->and(IosText::paste('secret'))->toBeFalse()
        ->and(IosText::paste("hello\n"))->toBeFalse()
        ->and(IosText::pieces('Ada@example.com'))->toBe(['Ada', '@', 'example', '.', 'com'])
        ->and(IosText::pieces('secret'))->toBe(['secret'])
        ->and(IosText::pieces("hello\n"))->toBe(["hello\n"]);
});

it('keeps spaces and percent signs for adb input text', function () {
    expect(AndroidText::argument("a b 100% +:@'"))->toBe("'a%sb%s100\\%%s+:@'\\'''");
});
