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

    /**
     * Notes the test's config, to send only what the test changes. Does nothing when the
     * tests do not boot Laravel.
     */
    public static function begin(): void
    {
        self::$pushed = null;
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
        if (self::$test === null) {
            return;
        }

        $payload = self::payload();

        if ($payload === null) {
            if (self::$pushed !== null) {
                $driver->removeAppFile('storage/'.Payload::FAKES);
                self::$pushed = null;
            }

            return;
        }

        $bytes = Payload::encode($payload, Payload::key());
        $hash = sha1($bytes);

        if ($hash === self::$pushed) {
            return;
        }

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
     * @return array{time: string, timezone: string}|null
     */
    private static function now(): ?array
    {
        if (! Carbon::hasTestNow()) {
            return null;
        }

        $now = Carbon::now();

        return ['time' => $now->format('Y-m-d H:i:s.u'), 'timezone' => $now->getTimezone()->getName()];
    }

    /**
     * The config keys the test changed, as dot keys. A key it removed is sent as null.
     *
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        $current = self::flatten(self::container()->make('config')->all());
        $changed = [];

        foreach ($current as $key => $value) {
            if (! array_key_exists($key, self::$config) || self::$config[$key] !== $value) {
                $changed[$key] = $value;
            }
        }

        foreach (array_diff_key(self::$config, $current) as $key => $value) {
            $changed[$key] = null;
        }

        return $changed;
    }

    /**
     * @return array{fakes: list<mixed>, prevent: bool, allow: list<string>}|null
     */
    private static function http(): ?array
    {
        $container = self::container();

        if (! $container->resolved(Factory::class)) {
            return null;
        }

        $factory = $container->make(Factory::class);

        return $factory instanceof RemoteHttpFactory ? $factory->remote() : null;
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
