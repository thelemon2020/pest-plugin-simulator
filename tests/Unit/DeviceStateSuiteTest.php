<?php

declare(strict_types=1);

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\Shutdown;
use NativePhp\Simulator\TestDatabase;
use Tests\Support\FakeDriver;

beforeEach(function () {
    $this->driver = new FakeDriver([]);
    Sessions::fake($this->driver);
    Configuration::configure(['scheme' => 'myapp']);
    TestDatabase::fake(null);
    fixStatusBar();
});

afterEach(function () {
    Sessions::fake(null);
    TestDatabase::fake(null);
    Configuration::reset();
    Shutdown::reset();
});

mobile(function () {
    it('keeps a request made in beforeEach for the test\'s first screen', function () {
        permissions([]);
        appearance('dark');

        screen('/lights');

        expect($this->driver->settings)->toBe([['statusBar', true], ['appearance', 'dark']])
            ->and(array_slice($this->driver->events, -3))->toBe([
                ['setting', 'statusBar'],
                ['setting', 'appearance'],
                ['open', 'myapp://lights'],
            ]);
    });
});
