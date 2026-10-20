<?php

use NativePhp\Simulator\Exceptions\AppError;

mobile(function () {
    it('fails at once on the exception the app logged, naming it', function () {
        $screen = screen('/boom')->assertSee('Ready to blow up');
        $started = microtime(true);

        expect(fn () => $screen->tap('Blow up')->assertSee('Never shown'))
            ->toThrow(AppError::class, 'The app logged RuntimeException: The sample app blew up on purpose.');

        // The tap, then at most a second's wait for the next read of the log. Without it,
        // assertSee() would wait out the whole timeout on NativePHP's error screen.
        expect(microtime(true) - $started)->toBeLessThan(5.0);
    });

    it('passes when the app logs the exception the test expects', function () {
        expectAppError(RuntimeException::class);

        // The test's end waits for the error. Nothing on NativePHP's error screen is worth
        // asserting on: with the idle marker patch, the marker reads busy under it for good.
        screen('/boom')->tap('Blow up');
    });
});
