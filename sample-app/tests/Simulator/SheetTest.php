<?php

mobile(function () {
    it('chooses from a bottom sheet', function () {
        screen('/sheet')
            ->tap('Open sheet')
            ->tap('Choose')
            ->assertSee('Chose from the sheet');
    });

    it('dismisses a bottom sheet', function () {
        screen('/sheet')
            ->tap('Open sheet')
            ->assertSee('Pick something')
            ->dismissSheet()
            ->assertSee('Sheet dismissed');
    });
});
