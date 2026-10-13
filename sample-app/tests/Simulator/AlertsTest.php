<?php

mobile(function () {
    it('answers an alert', function () {
        screen('/alerts')
            ->tap('Delete everything')
            ->alert('Delete')
            ->assertSee('Deleted');
    });
});
