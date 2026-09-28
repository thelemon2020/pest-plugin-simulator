<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidText;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Gesture;
use NativePhp\Simulator\Hid;

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

it('swipes in from the left edge to go back', function () {
    expect(Gesture::back(390, 844))->toBe([8.0, 422.0, 273.0, 422.0]);
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

it('selects all and deletes', function () {
    expect(Hid::selectAll())->toHaveCount(4)
        ->and(Hid::backspace())->toHaveCount(2);
});

it('keeps spaces and percent signs for adb input text', function () {
    expect(AndroidText::argument("a b 100% +:@'"))->toBe("a%sb%s100\\%%s+:@'");
});
