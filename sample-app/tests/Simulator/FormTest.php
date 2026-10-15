<?php

mobile(function () {
    it('types a name and saves it', function () {
        screen('/form')
            ->type('Name', 'Ada Lovelace')
            ->assertSee('Hello, Ada Lovelace')
            ->tap('Save')
            ->assertSee('Saved Ada Lovelace');
    });

    it('types an email address and a password', function () {
        screen('/form')
            ->type('Email', 'ada@example.com')
            ->assertValue('Email', 'ada@example.com')
            ->type('Password', 'hunter22');
    });

    // NativePHP 4.6 runs an Android field's @submit only from the keyboard's own Done key,
    // which a test cannot reach (docs/nativephp-idle-signal.md, "Also worth raising").
    it('submits a search with the return key', function () {
        screen('/form')
            ->submit('Search…', 'Talk Talk')
            ->assertSee('Searched Talk Talk');
    })->group('ios');

    it('opens the screen without copying the database', function () {
        keepDatabase();

        screen('/form')->assertSee('Hello, nobody');
    });
});
