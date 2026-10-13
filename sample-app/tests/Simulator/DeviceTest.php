<?php

mobile(function () {
    it('answers biometric prompts without failing', function () {
        enrollBiometrics();

        screen('/')
            ->matchBiometric()
            ->failBiometric()
            ->assertSee('Controls');
    });

    it('delivers a push notification', function () {
        screen('/')
            ->push('Order shipped', 'It arrives Tuesday', ['order' => 42])
            ->assertSee('Controls');
    })->group('ios');
});
