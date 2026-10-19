<?php

use NativePhp\Simulator\Run;

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
        // On GitHub's macOS runner a drag moved the slider by 50 to 85 percent of its length,
        // a different share each time, and slide() could not land on 75 in five drags: it
        // stopped at 67 in one run and at 76 in another.
        if (getenv('CI') && Run::device()->platform === 'ios') {
            $this->markTestSkipped('A drag on CI\'s iOS Simulator moves a slider by a different share of its length each time.');
        }

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
