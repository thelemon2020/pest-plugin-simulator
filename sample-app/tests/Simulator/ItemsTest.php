<?php

mobile(function () {
    it('scrolls a long list to a row it has not drawn yet', function () {
        screen('/items')
            ->scrollTo('Item 30')
            ->tap('Item 30')
            ->assertSee('Opened item 30');
    });
});
