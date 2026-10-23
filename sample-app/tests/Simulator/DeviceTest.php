<?php

mobile(function () {
    it('answers biometric prompts without failing', function () {
        enrollBiometrics();

        screen('/')
            ->matchBiometric()
            ->failBiometric()
            ->assertSee('Controls');
    });

    it('taps the app turned to landscape', function () {
        screen('/')
            ->assertSee('Controls')
            ->rotate('landscape')
            ->tap('Controls')
            ->toggle('Notifications', true)
            ->assertSee('Notifications are on');
    });

    it('delivers a push notification', function () {
        screen('/')
            ->push('Order shipped', 'It arrives Tuesday', ['order' => 42])
            ->assertSee('Controls');
    })->group('ios');
});
