<?php

declare(strict_types=1);

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
