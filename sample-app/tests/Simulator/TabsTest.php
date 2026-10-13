<?php

mobile(function () {
    it('changes the tab', function () {
        screen('/tabs')
            ->assertTabActive('Overview')
            ->changeTab('Activity')
            ->assertSee('Activity content');
    });

    // A baseline belongs to the machine that saved it: CI's emulator draws in software.
    it('looks the same as last time', function () {
        screen('/tabs')
            ->assertSee('Overview content')
            ->assertScreenshotMatches();
    })->skip((bool) getenv('CI'), 'Screenshot baselines are saved per machine.');
});
