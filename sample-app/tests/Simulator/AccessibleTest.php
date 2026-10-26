<?php

use PHPUnit\Framework\AssertionFailedError;

mobile(function () {
    it('passes on a screen whose controls all have labels', function () {
        screen('/controls')->assertAccessible();
    });

    it('names the field that has no label', function () {
        $screen = screen('/unlabeled')->assertSee('Nothing added');

        expect(fn () => $screen->assertAccessible())
            ->toThrow(AssertionFailedError::class, "1 control has no accessibility label, so a screen reader cannot name them:\n- TextField");
    });
});
