<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use NativePhp\Simulator\Driver;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Laravel;

/**
 * Sends the app what the test faked with Laravel's own API: Http::fake(), the time from
 * travelTo() or Carbon::setTestNow(), and config() values the test changed. It runs before
 * each step, and writes the fakes file and pushes it only when what the test faked has
 * changed since the last push. Fakes set before the first screen() go while the app is
 * stopped for the database copy, so the app has them when it boots.
 *
 * The app applies the file with Laravel's own fakes (see Mirror), and writes back what they
 * recorded (see Records).
 */
final class FakeSync
{
    /** This test's own id, which the app writes into its record file. Null outside a test. */
    private static ?string $test = null;

    /** @var array<string, mixed> */
    private static array $config = [];

    /** A hash of the fakes file last pushed, or null when the app has none from this test. */
    private static ?string $pushed = null;

    /** Whether this test has taken away the files a run that stopped part way left. */
    private static bool $cleared = false;

    /**
     * Notes the test's config, to send only what the test changes. Does nothing when the
     * tests do not boot Laravel.
     */
    public static function begin(): void
    {
        self::$pushed = null;
        self::$cleared = false;
        self::$test = null;
        self::$config = [];

        if (! Laravel::booted()) {
            return;
        }

        self::$test = bin2hex(random_bytes(8));
        self::$config = self::flatten(self::container()->make('config')->all());
    }

    public static function test(): ?string
    {
        return self::$test;
    }

    /**
     * Pushes the fakes file when what the test faked has changed, and removes the app's
     * when the test no longer fakes anything.
     */
    public static function sync(Driver $driver): void
    {
        $payload = self::$test === null ? null : self::payload();

        // A run stopped with Ctrl-C, or killed, never took its fakes back. The app would
        // apply them to this test, so the first screen() takes them away. A push replaces
        // the fakes file anyway.
        if (! self::$cleared) {
            self::$cleared = true;

            if ($payload === null) {
                $driver->removeAppFile('storage/'.Payload::FAKES);
            }

            $driver->removeAppFile('storage/'.Payload::RECORDS);
        }

        if (self::$test === null) {
            return;
        }

        if ($payload === null) {
            if (self::$pushed !== null) {
                $driver->removeAppFile('storage/'.Payload::FAKES);
                self::$pushed = null;
            }

            return;
        }

        $hash = Payload::fingerprint($payload);

        if ($hash === self::$pushed) {
            return;
        }

        $bytes = Payload::encode($payload, Payload::key());

        $local = tempnam(sys_get_temp_dir(), 'pest-simulator-fakes-');

        if ($local === false || file_put_contents($local, $bytes) === false) {
            throw new SimulatorException('Could not write the fakes file.');
        }

        try {
            $driver->pushAppFile($local, 'storage/'.Payload::FAKES);
        } finally {
            @unlink($local);
        }

        self::$pushed = $hash;
    }

    /**
     * Takes the fakes back off the app when the test ends, so the next test's app runs
     * with its real services.
     */
    public static function end(Driver $driver): void
    {
        try {
            if (self::$pushed !== null) {
                $driver->removeAppFile('storage/'.Payload::FAKES);
                $driver->removeAppFile('storage/'.Payload::RECORDS);
            }
        } finally {
            self::$pushed = null;
            self::$cleared = false;
            self::$test = null;
            self::$config = [];
        }
    }

    /**
     * What the fakes file holds now, or null when the test fakes nothing.
     *
     * @return array<string, mixed>|null
     */
    public static function payload(): ?array
    {
        $sections = array_filter([
            'now' => self::now(),
            'config' => self::config(),
            'http' => self::http(),
        ], fn (mixed $section): bool => $section !== null && $section !== []);

        return $sections === [] ? null : ['test' => self::$test] + $sections;
    }

    /**
     * The moment as a timestamp, which a wall clock time cannot always name: in the hour a
     * clock goes back, 01:30 happens twice.
     *
     * @return array{timestamp: string, timezone: string}|null
     */
    private static function now(): ?array
    {
        if (! Carbon::hasTestNow()) {
            return null;
        }

        $now = Carbon::now();

        return ['timestamp' => $now->format('U.u'), 'timezone' => $now->getTimezone()->getName()];
    }

    /**
     * The config keys the test changed, as dot keys, and the ones it removed. The app takes
     * the removed ones away first, then sets the rest. A key removed because a value now
     * sits under it, or because the array above it was replaced, is not sent: setting the
     * value does that.
     *
     * @return array{set?: array<string, mixed>, forget?: list<string>}
     */
    private static function config(): array
    {
        $current = self::flatten(self::container()->make('config')->all());
        $set = [];

        foreach ($current as $key => $value) {
            if (! array_key_exists($key, self::$config) || self::$config[$key] !== $value) {
                self::requirePlain($key, $value);
                $set[$key] = $value;
            }
        }

        $forget = [];

        foreach (array_keys(array_diff_key(self::$config, $current)) as $key) {
            if (self::under(array_keys($current), $key) || self::above(array_keys($set), $key)) {
                continue;
            }

            $forget[] = $key;
        }

        return array_filter(['set' => $set, 'forget' => $forget]);
    }

    /**
     * Only plain values reach the app. The fakes file unserializes no class of the app's
     * own, so an enum or another object would arrive broken.
     */
    private static function requirePlain(string $key, mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $inner) {
                self::requirePlain($key, $inner);
            }

            return;
        }

        if (is_object($value) && ! $value instanceof \Closure) {
            throw new SimulatorException("config('{$key}') holds a ".get_debug_type($value).', which cannot be sent to the app. Set a string, a number, a bool, null, or an array of them.');
        }
    }

    /**
     * Whether a key in $keys sits under $key.
     *
     * @param  list<string>  $keys
     */
    private static function under(array $keys, string $key): bool
    {
        foreach ($keys as $candidate) {
            if (str_starts_with($candidate, $key.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a key in $keys sits above $key.
     *
     * @param  list<string>  $keys
     */
    private static function above(array $keys, string $key): bool
    {
        foreach ($keys as $candidate) {
            if (str_starts_with($key, $candidate.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the test faked, with a fingerprint of each fake, so the app can add a fake the
     * test added without starting the others again.
     *
     * @return array{fakes: list<mixed>, ids: list<string>, prevent: bool, allow: list<string>}|null
     */
    private static function http(): ?array
    {
        $container = self::container();

        if (! $container->resolved(Factory::class)) {
            return null;
        }

        $factory = $container->make(Factory::class);
        $remote = $factory instanceof RemoteHttpFactory ? $factory->remote() : null;

        return $remote === null ? null : $remote + ['ids' => array_map(Payload::fingerprint(...), $remote['fakes'])];
    }

    /**
     * Config as dot keys down to each value that is not a keyed array. A list is one value.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $name = $prefix.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat += self::flatten($value, $name.'.');

                continue;
            }

            $flat[$name] = $value;
        }

        return $flat;
    }

    private static function container(): Container
    {
        return Container::getInstance();
    }
}
