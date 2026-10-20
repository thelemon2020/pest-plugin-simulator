<?php

declare(strict_types=1);

use NativePhp\Simulator\Device;
use NativePhp\Simulator\DeviceDatabase;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Screen;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\TestDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeDriver;

beforeEach(function () {
    $this->driver = new FakeDriver([[['label' => 'Paid', 'role' => 'StaticText', 'id' => null, 'center' => [10, 10]]]]);
    $this->failures = sys_get_temp_dir().'/simulator-device-db-'.uniqid('', true);
});

afterEach(function () {
    DeviceDatabase::reset();
    Sessions::fake(null);
    Run::clear();
    exec('rm -rf '.escapeshellarg($this->failures));
});

/**
 * A SQLite file's bytes, built by $build.
 *
 * @param  Closure(PDO): void  $build
 * @return array{0: string, 1: ?string} the database, and its write-ahead log when it kept one
 */
function sqliteBytes(Closure $build, bool $wal = false): array
{
    $path = sys_get_temp_dir().'/simulator-device-db-source-'.uniqid('', true).'.sqlite';
    $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    if ($wal) {
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA wal_autocheckpoint = 0');
    }

    $build($pdo);
    $database = (string) file_get_contents($path);
    $log = is_file($path.'-wal') ? (string) file_get_contents($path.'-wal') : null;
    $pdo = null;

    foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    return [$database, $log];
}

function orders(string ...$statuses): string
{
    return sqliteBytes(function (PDO $pdo) use ($statuses): void {
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT, paid_at TEXT NULL)');

        foreach ($statuses as $status) {
            $pdo->prepare('INSERT INTO orders (status, paid_at) VALUES (?, ?)')->execute([$status, $status === 'paid' ? '2026-12-24 09:00:00' : null]);
        }
    })[0];
}

function deviceScreen(FakeDriver $driver, float $timeout = 0, string $failures = ''): Screen
{
    return new Screen($driver, timeoutSeconds: $timeout, failureDirectory: $failures);
}

it('finds a row the app wrote', function () {
    $this->driver->appFiles['database/database.sqlite'] = orders('unpaid', 'paid', 'unpaid');

    deviceScreen($this->driver)
        ->assertDatabaseHas('orders', ['id' => 2, 'status' => 'paid'])
        ->assertDatabaseHas('orders', ['id' => 1, 'paid_at' => null])
        ->assertDatabaseMissing('orders', ['id' => 1, 'status' => 'paid'])
        ->assertDatabaseCount('orders', 3);

    expect(array_filter($this->driver->events, fn (array $event): bool => $event === ['pullFile', 'database/database.sqlite']))->toHaveCount(4);
});

it('takes the same values Laravel binds', function () {
    $this->driver->appFiles['database/database.sqlite'] = sqliteBytes(function (PDO $pdo): void {
        $pdo->exec('CREATE TABLE flags (id INTEGER PRIMARY KEY, on_sale INTEGER, kind TEXT, starts_at TEXT)');
        $pdo->exec("INSERT INTO flags (on_sale, kind, starts_at) VALUES (1, 'express', '2026-12-24 09:00:00')");
    })[0];

    expect(deviceScreen($this->driver)->assertDatabaseHas('flags', [
        'on_sale' => true,
        'kind' => DeviceDatabaseKind::Express,
        'starts_at' => new DateTimeImmutable('2026-12-24 09:00:00'),
    ]))->toBeInstanceOf(Screen::class);
});

enum DeviceDatabaseKind: string
{
    case Express = 'express';
}

it('waits for a row the app has not written yet', function () {
    $this->driver->appFiles['database/database.sqlite'] = orders('unpaid', 'unpaid');
    $pulls = 0;
    $this->driver->pulling = function (string $relative) use (&$pulls): void {
        if ($relative === 'database/database.sqlite' && ++$pulls === 3) {
            $this->driver->appFiles['database/database.sqlite'] = orders('unpaid', 'paid');
        }
    };

    deviceScreen($this->driver, timeout: 5)->assertDatabaseHas('orders', ['id' => 2, 'status' => 'paid']);

    expect($pulls)->toBe(3);
});

it('says what the table holds when the row is not there, and saves the pull', function () {
    $this->driver->appFiles['database/database.sqlite'] = orders('unpaid', 'unpaid', 'refunded', 'unpaid', 'unpaid');

    try {
        deviceScreen($this->driver, failures: $this->failures)->assertDatabaseHas('orders', ['status' => 'paid', 'id' => 2]);
        $this->fail('The assertion passed.');
    } catch (AssertionFailedError $error) {
        expect($error->getMessage())
            ->toStartWith("Did not find a row in the device's [orders] table matching {\n    \"status\": \"paid\",\n    \"id\": 2\n}.\n\nFound: [\n    {\n        \"status\": \"unpaid\",\n        \"id\": 1\n    },")
            ->toContain(' and 2 others.')
            ->toContain("Saved {$this->failures}/device.sqlite")
            ->and(file_get_contents($this->failures.'/device.sqlite'))->toStartWith('SQLite format 3');
    }
});

it('shows rows that share the first value asked for', function () {
    $this->driver->appFiles['database/database.sqlite'] = orders('unpaid', 'paid');

    expect(fn () => deviceScreen($this->driver, failures: $this->failures)->assertDatabaseHas('orders', ['status' => 'paid', 'id' => 1]))
        ->toThrow(AssertionFailedError::class, "Found similar results: [\n    {\n        \"status\": \"paid\",\n        \"id\": 2\n    }\n]");
});

it('says when the table is empty', function () {
    $this->driver->appFiles['database/database.sqlite'] = orders();

    expect(fn () => deviceScreen($this->driver, failures: $this->failures)->assertDatabaseHas('orders', ['id' => 1]))
        ->toThrow(AssertionFailedError::class, 'The table is empty.');
});

it('names the rows it should not have found', function () {
    $this->driver->appFiles['database/database.sqlite'] = orders('paid');

    expect(fn () => deviceScreen($this->driver, failures: $this->failures)->assertDatabaseMissing('orders', ['status' => 'paid']))
        ->toThrow(AssertionFailedError::class, "Still found a row in the device's [orders] table matching {\n    \"status\": \"paid\"\n}.\n\nFound: [\n    {\n        \"id\": 1,");
});

it('counts the rows', function () {
    $this->driver->appFiles['database/database.sqlite'] = orders('paid', 'paid');

    expect(fn () => deviceScreen($this->driver, failures: $this->failures)->assertDatabaseCount('orders', 3))
        ->toThrow(AssertionFailedError::class, "The device's [orders] table has 2 rows, not 3.");
});

it('says when the table is not there', function () {
    $this->driver->appFiles['database/database.sqlite'] = orders();

    expect(fn () => deviceScreen($this->driver, failures: $this->failures)->assertDatabaseCount('invoices', 0))
        ->toThrow(AssertionFailedError::class, "The device's database has no [invoices] table.");
});

it('says when the app has no database', function () {
    expect(fn () => deviceScreen($this->driver, failures: $this->failures)->assertDatabaseCount('orders', 0))
        ->toThrow(AssertionFailedError::class, 'The app has no database yet. There is no database/database.sqlite in its storage.');
});

it('says when a column is not there', function () {
    $this->driver->appFiles['database/database.sqlite'] = orders('paid');

    expect(fn () => deviceScreen($this->driver, failures: $this->failures)->assertDatabaseHas('orders', ['total' => 42]))
        ->toThrow(AssertionFailedError::class, "The device's [orders] table has no [total] column.");
});

it('pulls again when a copy is torn', function () {
    $good = orders('paid');
    $this->driver->appFiles['database/database.sqlite'] = substr($good, 0, 100).str_repeat("\xff", strlen($good) - 100);
    $pulls = 0;
    $this->driver->pulling = function (string $relative) use (&$pulls, $good): void {
        if ($relative === 'database/database.sqlite' && ++$pulls === 2) {
            $this->driver->appFiles['database/database.sqlite'] = $good;
        }
    };

    deviceScreen($this->driver)->assertDatabaseHas('orders', ['status' => 'paid']);

    expect($pulls)->toBe(2);
});

it('reads rows the app has only in its write-ahead log', function () {
    [$database, $log] = sqliteBytes(function (PDO $pdo): void {
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT)');
        $pdo->exec("INSERT INTO orders (status) VALUES ('paid')");
    }, wal: true);

    expect($log)->not->toBeNull();

    $this->driver->appFiles['database/database.sqlite'] = $database;
    $this->driver->appFiles['database/database.sqlite-wal'] = (string) $log;

    deviceScreen($this->driver)->assertDatabaseHas('orders', ['status' => 'paid']);
});

it('hands a test the pull until its next step, with writes turned off', function () {
    Run::useDevice(new Device('ios', 'iPhone 17', true));
    Sessions::fake($this->driver);
    $this->driver->appFiles['database/database.sqlite'] = orders('paid');

    $first = deviceDatabase();

    expect(deviceDatabase())->toBe($first)
        ->and($first->query('SELECT status FROM orders')->fetchColumn())->toBe('paid')
        ->and(fn () => $first->exec("UPDATE orders SET status = 'refunded'"))->toThrow(PDOException::class);

    $this->driver->appFiles['database/database.sqlite'] = orders('refunded');
    deviceScreen($this->driver)->assertSee('Paid');

    expect(deviceDatabase()->query('SELECT status FROM orders')->fetchColumn())->toBe('refunded')
        ->and($first->query('SELECT status FROM orders')->fetchColumn())->toBe('paid');
});

it('deletes its pulls when the test begins and ends', function () {
    Run::useDevice(new Device('ios', 'iPhone 17', true));
    Sessions::fake($this->driver);
    $this->driver->appFiles['database/database.sqlite'] = orders('paid');
    $directory = sys_get_temp_dir().'/pest-simulator-'.getmypid();

    deviceDatabase();
    expect(glob($directory.'/device-*.sqlite'))->toHaveCount(1);

    TestDatabase::end();

    expect(glob($directory.'/device-*.sqlite'))->toBe([]);
});

it('only works inside a mobile suite', function () {
    deviceDatabase();
})->throws(SimulatorException::class, 'deviceDatabase() only works inside a mobile() suite.');
