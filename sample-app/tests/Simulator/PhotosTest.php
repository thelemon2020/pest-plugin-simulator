<?php

mobile(function () {
    it('picks a photo it added to the library', function () {
        addPhoto('tests/fixtures/photo.jpg');

        screen('/photos')
            ->tap('Pick photo')
            ->pickPhoto()
            ->assertSee('Picked 1 photo');
    });
});
