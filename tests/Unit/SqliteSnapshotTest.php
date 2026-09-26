<?php

declare(strict_types=1);

use NativePhp\Simulator\SqliteSnapshot;

it('copies uncommitted rows', function () {
    $source = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $source->exec('CREATE TABLE rooms (id integer primary key, name text not null)');
    $source->exec('CREATE TABLE lights (id integer primary key autoincrement, room_id integer not null references rooms(id), name text not null)');
    $source->exec('CREATE INDEX lights_name ON lights (name)');
    $source->beginTransaction();
    $source->exec("INSERT INTO rooms (id, name) VALUES (1, 'Hall')");
    $source->exec("INSERT INTO lights (name, room_id) VALUES ('Kitchen', 1)");
    $source->exec("UPDATE sqlite_sequence SET seq = 9 WHERE name = 'lights'");

    $path = sys_get_temp_dir().'/simulator-snapshot-'.uniqid('', true).'.sqlite';

    try {
        SqliteSnapshot::write($source, $path);

        $copy = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $light = $copy->query('SELECT id, name, room_id FROM lights')->fetch(PDO::FETCH_ASSOC);

        expect($light['name'])->toBe('Kitchen')
            ->and((int) $light['id'])->toBe(1)
            ->and((int) $light['room_id'])->toBe(1)
            ->and($copy->query('SELECT name FROM rooms')->fetchColumn())->toBe('Hall')
            ->and((int) $copy->query("SELECT seq FROM sqlite_sequence WHERE name = 'lights'")->fetchColumn())->toBe(9)
            ->and($copy->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'lights_name'")->fetchColumn())->toContain('name');

        $source->rollBack();

        expect((int) $source->query('SELECT COUNT(*) FROM lights')->fetchColumn())->toBe(0);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('skips generated columns', function () {
    $source = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $source->exec('CREATE TABLE products (price real not null, discounted real generated always as (price * 0.9) stored)');
    $source->exec('INSERT INTO products (price) VALUES (10)');

    $path = sys_get_temp_dir().'/simulator-snapshot-'.uniqid('', true).'.sqlite';

    try {
        SqliteSnapshot::write($source, $path);

        $copy = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $product = $copy->query('SELECT price, discounted FROM products')->fetch(PDO::FETCH_ASSOC);

        expect((float) $product['price'])->toBe(10.0)
            ->and(round((float) $product['discounted'], 2))->toBe(9.0);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('skips fts virtual tables and their shadow tables', function () {
    $source = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    try {
        $source->exec('CREATE TABLE posts (id integer primary key, title text not null)');
        $source->exec('CREATE VIRTUAL TABLE posts_fts USING fts5(title)');
    } catch (PDOException) {
        $this->markTestSkipped('fts5 is not available');
    }

    $source->exec("INSERT INTO posts (title) VALUES ('Kitchen')");
    $source->exec("INSERT INTO posts_fts (title) VALUES ('Kitchen')");

    $path = sys_get_temp_dir().'/simulator-snapshot-'.uniqid('', true).'.sqlite';

    try {
        SqliteSnapshot::write($source, $path);

        $copy = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $names = $copy->query("SELECT name FROM sqlite_master WHERE name LIKE 'posts%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

        expect($copy->query('SELECT title FROM posts')->fetchColumn())->toBe('Kitchen')
            ->and($names)->toBe(['posts']);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});
