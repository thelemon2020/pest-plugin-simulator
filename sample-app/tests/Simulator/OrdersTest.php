<?php

mobile(function () {
    it('pays the second of three orders', function () {
        screen('/orders')
            ->nth(2)->tap('Pay')
            ->assertSee('Paid');
    });
});
