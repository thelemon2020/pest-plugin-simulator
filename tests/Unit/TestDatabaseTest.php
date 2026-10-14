<?php

declare(strict_types=1);

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\TestDatabase;
use Tests\Support\FakeDriver;

afterEach(function () {
    TestDatabase::fake(null);
    TestDatabase::end();
});

it('rejects an in-memory host database before installing', function () {
    TestDatabase::begin();
    TestDatabase::connection(fn (): object => new class
    {
        public function getDriverName(): string
        {
            return 'sqlite';
        }

        public function getDatabaseName(): string
        {
            return ':memory:';
        }
    });
    $driver = new FakeDriver([]);

    expect(fn () => TestDatabase::publish($driver))
        ->toThrow(SimulatorException::class, 'file-backed SQLite database');

    expect($driver->databases)->toBe([]);
});

it('rejects a host connection that is not sqlite', function () {
    TestDatabase::begin();
    TestDatabase::connection(fn (): object => new class
    {
        public function getDriverName(): string
        {
            return 'mysql';
        }

        public function getDatabaseName(): string
        {
            return 'lights';
        }
    });
    $driver = new FakeDriver([]);

    expect(fn () => TestDatabase::publish($driver))
        ->toThrow(SimulatorException::class, 'DB_CONNECTION=sqlite');

    expect($driver->databases)->toBe([]);
});

it('copies a file-backed host database on publish', function () {
    $source = tempnam(sys_get_temp_dir(), 'host-');
    $pdo = new PDO('sqlite:'.$source, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('CREATE TABLE rooms (name text not null)');
    $pdo->exec("INSERT INTO rooms (name) VALUES ('Hall')");

    TestDatabase::begin();
    TestDatabase::connection(fn (): object => new class($pdo, $source)
    {
        public function __construct(private PDO $pdo, private string $path) {}

        public function getDriverName(): string
        {
            return 'sqlite';
        }

        public function getDatabaseName(): string
        {
            return $this->path;
        }

        public function getPdo(): PDO
        {
            return $this->pdo;
        }
    });
    $driver = new FakeDriver([]);

    try {
        TestDatabase::publish($driver);

        $copy = tempnam(sys_get_temp_dir(), 'copy-');
        file_put_contents($copy, $driver->databaseContents);
        $snapshot = new PDO('sqlite:'.$copy, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        expect($snapshot->query('SELECT name FROM rooms')->fetchColumn())->toBe('Hall')
            ->and($driver->databases)->toHaveCount(1);

        TestDatabase::publish($driver);

        expect($driver->databases)->toHaveCount(1);
    } finally {
        if (is_file($source)) {
            unlink($source);
        }

        if (isset($copy) && is_file($copy)) {
            unlink($copy);
        }
    }
});

it('leaves the app database alone in a test that keeps it', function () {
    $snapshot = tempnam(sys_get_temp_dir(), 'snapshot-');
    TestDatabase::fake(fn (): string => $snapshot);
    TestDatabase::begin();
    TestDatabase::keep();
    $driver = new FakeDriver([]);

    TestDatabase::publish($driver);

    expect($driver->databases)->toBe([])
        ->and(is_file($snapshot))->toBeTrue();

    unlink($snapshot);
});

it('copies again in the next test after one that kept the database', function () {
    $snapshot = tempnam(sys_get_temp_dir(), 'snapshot-');
    TestDatabase::fake(fn (): string => $snapshot);
    TestDatabase::begin();
    TestDatabase::keep();
    TestDatabase::end();
    TestDatabase::begin();
    $driver = new FakeDriver([]);

    TestDatabase::publish($driver);

    expect($driver->databases)->toHaveCount(1);
});

it('copies no database when the suite turns copying off', function () {
    Configuration::configure(['copy_database' => false]);
    $snapshot = tempnam(sys_get_temp_dir(), 'snapshot-');
    TestDatabase::fake(fn (): string => $snapshot);
    TestDatabase::begin();
    $driver = new FakeDriver([]);

    try {
        TestDatabase::publish($driver);

        expect($driver->databases)->toBe([]);
    } finally {
        Configuration::reset();
        unlink($snapshot);
    }
});

it('says whether the next screen stops the app to copy the database', function () {
    $snapshot = tempnam(sys_get_temp_dir(), 'snapshot-');
    TestDatabase::fake(fn (): string => $snapshot);
    TestDatabase::begin();

    try {
        $first = TestDatabase::stopsApp();
        TestDatabase::publish(new FakeDriver([]));
        $second = TestDatabase::stopsApp();
        TestDatabase::begin();
        TestDatabase::keep();
        $kept = TestDatabase::stopsApp();
        TestDatabase::begin();
        Configuration::configure(['copy_database' => false]);
        $off = TestDatabase::stopsApp();

        expect([$first, $second, $kept, $off])->toBe([true, false, false, false]);
    } finally {
        Configuration::reset();
        TestDatabase::fake(null);
    }
});

it('refuses keepDatabase() after the database was already copied', function () {
    TestDatabase::fake(fn (): string => tempnam(sys_get_temp_dir(), 'snapshot-'));
    TestDatabase::begin();
    TestDatabase::publish(new FakeDriver([]));

    expect(fn () => TestDatabase::keep())
        ->toThrow(SimulatorException::class, 'keepDatabase() has to come before the first screen()');
});
