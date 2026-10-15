<?php

use NativePhp\Simulator\IdleMarker;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Sessions;

mobile(function () {
    it('waits for PHP that takes its time', function () {
        $screen = screen('/slow')->assertSee('Not started');

        // Each keystroke into this field takes PHP 300ms. Typed in one burst, an answer that
        // comes back after the next keystroke writes the older value back, so the test only
        // holds when the app says how far behind PHP is (docs/nativephp-idle-signal.md).
        $marked = collect(Sessions::get(Run::device())->describe())
            ->contains(fn (array $element): bool => ($element['id'] ?? null) === IdleMarker::ID);

        if (! $marked) {
            $this->markTestSkipped('This NativePHP build shows no idle marker.');
        }

        $screen
            ->type('Echo', 'hello there')
            ->assertSee('Echoed: hello there')
            ->tap('Start work')
            ->assertSee('Finished');
    });
});
