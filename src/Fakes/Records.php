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

            $bytes = (string) file_get_contents($local);
            $records = Models::fromDevice($bytes, fn (): mixed => @unserialize($bytes));
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

            try {
                $assert();

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
        $assert();
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
