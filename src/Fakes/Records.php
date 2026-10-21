<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Closure;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Driver;
use NativePhp\Simulator\IdleMarker;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Sessions;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionClass;
use Throwable;

/**
 * The record file: what the app's fakes recorded, which the app writes after each render
 * (see Mirror). The test's Remote fakes read it before each assert, and add what it holds
 * to their own arrays, so Laravel's own asserts check what the app did.
 *
 * A file names the test whose fakes recorded it. One left by an earlier test, or by a run
 * that stopped part way, reads as nothing.
 */
final class Records
{
    /** When an assert last waited for the app and read its record. */
    private static float $waitedAt = 0.0;

    /**
     * Whether an assert read the app's record after $time: it waited for the app then,
     * so the end of the test has nothing more to wait for.
     */
    public static function waitedSince(float $time): bool
    {
        return self::$waitedAt >= $time;
    }

    /** The key an entry serialized on its own sits under, in the app's record. */
    public const ENTRY = "\0pest-simulator-entry";

    /**
     * A section of the record with its entries unserialized, each on its own, with its
     * models loaded from the device database (see Models). An entry that cannot be read,
     * such as one whose model the app has since deleted, does not take the rest with it.
     * It stays as an instance of its class with nothing set, so assertSent(Receipt::class),
     * a count, and assertNothingSent() still see it was sent. Only a closure that reads
     * its models cannot.
     *
     * @param  array<array-key, mixed>  $section
     * @return array<array-key, mixed>
     */
    public static function entries(array $section): array
    {
        $entries = [];

        foreach ($section as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if (array_keys($value) === [self::ENTRY] && is_string($value[self::ENTRY])) {
                $bytes = $value[self::ENTRY];

                try {
                    $entries[$key] = Models::fromDevice($bytes, fn (): mixed => unserialize($bytes));
                } catch (Throwable) {
                    $standIn = self::standIn($bytes);

                    if ($standIn !== null) {
                        $entries[$key] = $standIn;
                    }
                }

                continue;
            }

            $entries[$key] = self::entries($value);
        }

        return array_is_list($section) ? array_values($entries) : $entries;
    }

    /**
     * An instance of the class a serialized entry names, with no constructor run and
     * nothing set. Events are recorded as the arguments they were dispatched with, the event first.
     */
    private static function standIn(string $bytes): mixed
    {
        if (preg_match('/^a:\d+:\{i:0;O:\d+:"([^"]+)"/', $bytes, $match) === 1 && class_exists($match[1])) {
            return [(new ReflectionClass($match[1]))->newInstanceWithoutConstructor()];
        }

        if (preg_match('/^O:\d+:"([^"]+)"/', $bytes, $match) === 1 && class_exists($match[1])) {
            return (new ReflectionClass($match[1]))->newInstanceWithoutConstructor();
        }

        return null;
    }

    /**
     * What the app recorded for this test, by fake: `http`, `mail`, and the rest.
     *
     * @return array<string, mixed>
     */
    public static function pull(): array
    {
        if (! Run::inside() || FakeSync::test() === null) {
            return [];
        }

        $driver = Sessions::get(Run::device());
        $local = tempnam(sys_get_temp_dir(), 'pest-simulator-records-');

        if ($local === false) {
            return [];
        }

        try {
            if (! $driver->pullAppFile('storage/'.Payload::RECORDS, $local)) {
                return [];
            }

            // The app writes arrays, with each entry in them serialized on its own (see
            // entries()). Nothing in the file becomes an object here.
            $records = @unserialize((string) file_get_contents($local), ['allowed_classes' => false]);
        } finally {
            @unlink($local);
        }

        if (! is_array($records) || ($records['test'] ?? null) !== FakeSync::test()) {
            return [];
        }

        /** @var array<string, mixed> $records */
        return $records;
    }

    /**
     * Runs a positive assert until it passes or the timeout runs out, pulling the record
     * file before each try: the app may not have done the thing yet.
     *
     * @param  Closure(): void  $pull  reads the record file into the fake
     * @param  Closure(): void  $assert
     */
    public static function eventually(Closure $pull, Closure $assert): void
    {
        if (! Run::inside()) {
            $assert();

            return;
        }

        $timeout = Configuration::resolve()->timeout();
        $deadline = microtime(true) + $timeout;

        while (true) {
            $pull();
            self::$waitedAt = microtime(true);

            try {
                self::attempt($assert);

                return;
            } catch (AssertionFailedError $failure) {
                if ($timeout <= 0 || microtime(true) >= $deadline) {
                    throw $failure;
                }
            }

            usleep(400_000);
        }
    }

    /**
     * Runs a negative assert once, after the app has taken every event it was sent, when
     * it shows the IdleMarker. Without the marker there is nothing to wait for, and the
     * assert reads the record file as it is.
     *
     * @param  Closure(): void  $pull
     * @param  Closure(): void  $assert
     */
    public static function settled(Closure $pull, Closure $assert): void
    {
        if (Run::inside()) {
            self::idle(Sessions::get(Run::device()));
        }

        $pull();
        self::$waitedAt = microtime(true);
        self::attempt($assert);
    }

    /**
     * Runs an assert. A closure that reads a model from an entry the app recorded, whose
     * model is no longer on the device (see entries()), finds the property unset; that is
     * a failed assertion that says why, not an Error.
     *
     * @param  Closure(): void  $assert
     */
    private static function attempt(Closure $assert): void
    {
        try {
            $assert();
        } catch (\Error $error) {
            if (! str_contains($error->getMessage(), 'must not be accessed before initialization')) {
                throw $error;
            }

            throw new AssertionFailedError('An entry the app recorded could not be read back whole: a model it holds is no longer in the device database. '.$error->getMessage(), 0, $error);
        }
    }

    private static function idle(Driver $driver): void
    {
        $timeout = Configuration::resolve()->timeout();
        $deadline = microtime(true) + $timeout;

        do {
            try {
                [, $pending] = IdleMarker::split($driver->describe());
            } catch (Throwable) {
                return;
            }

            if ($pending === null || $pending === 0 || $timeout <= 0) {
                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);
    }
}
