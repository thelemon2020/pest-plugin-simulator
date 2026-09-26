<?php

declare(strict_types=1);

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\TestDatabase;
use Tests\Support\FakeDriver;

beforeEach(function () {
    $this->driver = new FakeDriver([]);
    $this->path = tempnam(sys_get_temp_dir(), 'simulator-');
    Configuration::configure(['scheme' => 'myapp']);
    Sessions::fake($this->driver);
    TestDatabase::fake(fn (): string => $this->path);
});

afterEach(function () {
    Sessions::fake(null);
    TestDatabase::fake(null);

    if (is_string($this->path) && is_file($this->path)) {
        unlink($this->path);
    }
});

mobile(function () {
    it('copies the database on the first screen', function () {
        expect($this->driver->databases)->toBe([]);

        screen('/lights');

        expect($this->driver->events)->toBe([
            ['install', $this->path],
            ['open', 'myapp://lights'],
        ]);

        screen('/settings');

        expect($this->driver->events)->toBe([
            ['install', $this->path],
            ['open', 'myapp://lights'],
            ['open', 'myapp://settings'],
        ]);
    });

    it('copies again for the next test', function () {
        screen('/lights');

        expect($this->driver->events)->toBe([
            ['install', $this->path],
            ['open', 'myapp://lights'],
        ]);
    });
});
