<?php

declare(strict_types=1);

use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Run;

mobile(function () {
    it('runs on each default device', function () {
        expect(Run::device()->name)->toBeIn(['iPhone Latest', 'Pixel Default']);
    });

    it('can limit one test to ios', function () {
        expect(Run::device()->platform)->toBe('ios');
    })->group('ios');
});

mobile(function () {
    it('runs on each named iphone', function () {
        expect(Run::device()->name)->toBeIn(['iPhone 17 Pro', 'iPad Air'])
            ->and(Run::device()->platform)->toBe('ios');
    });
})->ios(['iPhone 17 Pro', 'iPad Air']);

mobile(function () {
    it('drops android when the test is grouped ios', function () {
        expect(Run::device()->platform)->toBe('ios');
    })->group('ios');
})->devices(ios: ['iPhone 17 Pro'], android: ['Pixel 8']);

describe('settings', function () {
    mobile(function () {
        it('inherits the ios group', function () {
            expect(Run::device()->platform)->toBe('ios');
        });
    })->devices(ios: ['iPhone 17 Pro'], android: ['Pixel 8']);
})->group('ios');

it('refuses screen outside a mobile suite', function () {
    screen('/lights');
})->throws(SimulatorException::class);
