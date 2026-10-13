<?php

mobile(function () {
    it('turns a switch on and checks a box', function () {
        screen('/controls')
            ->toggle('Notifications', true)
            ->assertSee('Notifications are on')
            ->assertChecked('Notifications')
            ->check('Subscribe')
            ->assertSee('Subscribed: yes');
    });

    it('slides the volume', function () {
        screen('/controls')
            ->slide('Volume', 75, min: 0, max: 100)
            ->assertSee('Volume: 75');
    });

    it('picks a size from the select', function () {
        screen('/controls')
            ->select('Size', 'Large')
            ->assertSee('Size: Large');
    });

    it('picks a plan', function () {
        screen('/controls')
            ->selectRadio('Plan', 'Pro')
            ->assertSee('Plan: pro');
    });
});
