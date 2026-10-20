<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\AppError;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

/**
 * What the app's PHP logged while a test drove it. An exception in a NativePHP component is
 * caught, handed to report(), and drawn as NativePHP's own error screen, so without this a
 * test only sees a screen that never shows what it waits for, and fails at the timeout.
 *
 * The test's first screen() notes where laravel.log ends. Each step reads what the app
 * wrote after that, and a wait reads it at most once a second, so an error ends the step
 * that caused it and names the exception, its message, and where it was thrown.
 *
 * Laravel writes one entry with one write, a header line and the stack after it:
 *
 *     [2026-10-20 13:48:02] local.ERROR: Order 2 was paid already. {"exception":"[object] (RuntimeException(code: 0): Order 2 was paid already. at /…/app/NativeComponents/Checkout.php:31)
 *     [stacktrace]
 *     #0 /…/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): App\NativeComponents\Checkout->pay()
 *
 * @phpstan-type Entry array{level: string, header: string, message: string, class: ?string, where: ?string, frames: list<string>}
 */
final class AppLog
{
    /**
     * The `single` channel's laravel.log and the `daily` channel's laravel-2026-10-20.log:
     * whichever the app wrote last.
     */
    public const PATTERN = 'storage/logs/laravel*.log';

    /** What counts as an error: Laravel's levels from ERROR up. */
    private const LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /** How many stack frames a failure shows. */
    private const FRAMES = 5;

    /** The shortest time between two reads inside a wait. */
    private const INTERVAL = 1.0;

    private static bool $started = false;

    private static ?string $path = null;

    private static int $offset = 0;

    /** The part of a line the app had not finished writing at the last read. */
    private static string $partial = '';

    private static float $readAt = 0.0;

    private static bool $allowed = false;

    /** @var list<string> */
    private static array $expected = [];

    /** @var list<string> */
    private static array $seen = [];

    public static function begin(): void
    {
        self::$started = false;
        self::$path = null;
        self::$offset = 0;
        self::$partial = '';
        self::$readAt = 0.0;
        self::$allowed = false;
        self::$expected = [];
        self::$seen = [];
    }

    /**
     * This test's errors in the app's log do not fail it.
     */
    public static function allow(): void
    {
        self::$allowed = true;
    }

    /**
     * The app logs this exception, or one that extends it, before the test ends. It does
     * not fail the test, and a test that ends without it fails.
     *
     * @param  class-string|string  $class
     */
    public static function expect(string $class): void
    {
        self::$expected[] = ltrim($class, '\\');
    }

    /**
     * Notes where the app's log ends, once a test, so only what the app writes after
     * this counts. The first screen() calls it after the database copy, before it opens
     * the route.
     */
    public static function start(Driver $driver): void
    {
        if (self::$started || ! Configuration::resolve()->checksAppErrors()) {
            return;
        }

        self::$started = true;
        self::$readAt = microtime(true);

        $tail = $driver->tailAppFile(self::PATTERN, PHP_INT_MAX);

        if ($tail !== null) {
            self::$path = $tail['path'];
            self::$offset = $tail['size'];
        }
    }

    /**
     * Reads what the app logged since the last read, and throws when it was an error.
     */
    public static function check(Driver $driver): void
    {
        if (! self::$started) {
            return;
        }

        self::$readAt = microtime(true);
        $entries = self::read($driver);

        foreach ($entries as $entry) {
            if (! in_array($entry['level'], self::LEVELS, true)) {
                continue;
            }

            $expected = self::expectedMatch($entry['class']);

            if ($expected !== null) {
                self::$seen[] = $expected;

                continue;
            }

            if (self::$allowed) {
                continue;
            }

            throw new AppError(self::describe($entry), $entry['class']);
        }
    }

    /**
     * check(), if a second has passed since the last read. A wait calls it on every read.
     */
    public static function poll(Driver $driver): void
    {
        if (self::$started && microtime(true) - self::$readAt >= self::INTERVAL) {
            self::check($driver);
        }
    }

    /**
     * Reads the log until the error expectAppError() named is there, for up to the
     * timeout, when the test ends: the tap that throws can be the test's last step, and it
     * does not wait for the app. Fails a test whose expected error the app never logged.
     */
    public static function finish(Driver $driver): void
    {
        if (self::$expected === []) {
            return;
        }

        $timeout = Configuration::resolve()->timeout();
        $deadline = microtime(true) + $timeout;

        do {
            self::check($driver);
            $missing = array_values(array_diff(self::$expected, self::$seen));

            if ($missing === [] || $timeout <= 0) {
                break;
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        if ($missing !== []) {
            throw new AssertionFailedError('The app did not log ['.implode('], [', $missing).'], which expectAppError() waited for.');
        }

        // An expected error is the test's assertion, as expectException() is.
        Assert::assertEmpty($missing);
    }

    /**
     * Whether an expectAppError() is waiting, so the test end has to read the log.
     */
    public static function expecting(): bool
    {
        return self::$expected !== [];
    }

    /**
     * Turns the entries in a stretch of log into what a failure needs.
     *
     * @return list<Entry>
     */
    public static function parse(string $text): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^\[\d{4}-\d{2}-\d{2}[ T][^\]]*\] [\w-]+\.([A-Z]+): ?(.*)$/', $line, $match) === 1) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = self::header($line, $match[1], $match[2]);

                continue;
            }

            if ($current !== null && count($current['frames']) < self::FRAMES && preg_match('/^#\d+ /', $line) === 1) {
                $current['frames'][] = self::shorten(stripslashes($line));
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        return $entries;
    }

    /**
     * @return list<Entry>
     */
    private static function read(Driver $driver): array
    {
        $tail = $driver->tailAppFile(self::PATTERN, self::$path === null ? 0 : self::$offset);

        if ($tail === null) {
            return [];
        }

        // A new day's file, or one that was started again, is read from its start.
        $restarted = $tail['path'] !== self::$path || self::$offset > $tail['size'];
        $start = $restarted ? 0 : self::$offset;
        $bytes = ($restarted ? '' : self::$partial).$tail['bytes'];
        self::$path = $tail['path'];

        // The last line may still be on its way. It is read again next time, whole.
        $end = strrpos($bytes, "\n");
        $complete = $end === false ? '' : substr($bytes, 0, $end + 1);
        self::$partial = $end === false ? $bytes : substr($bytes, $end + 1);
        self::$offset = $start + strlen($tail['bytes']);

        return self::parse($complete);
    }

    /**
     * @return Entry
     */
    private static function header(string $line, string $level, string $rest): array
    {
        $class = null;
        $where = null;
        $message = $rest;
        $context = strpos($rest, ' {"exception":"[object] (');

        if ($context !== false) {
            $message = substr($rest, 0, $context);
            $exception = stripslashes(substr($rest, $context + strlen(' {"exception":"[object] (')));

            if (preg_match('/^([A-Za-z_][\w\\\\]*)\(code: [^)]*\): (.*) at (\S+):(\d+)\)/s', $exception, $match) === 1) {
                $class = $match[1];
                $message = $match[2];
                $where = self::shorten($match[3]).':'.$match[4];
            }
        }

        return ['level' => $level, 'header' => $line, 'message' => trim($message), 'class' => $class, 'where' => $where, 'frames' => []];
    }

    /**
     * @param  Entry  $entry
     */
    private static function describe(array $entry): string
    {
        $name = $entry['class'] ?? $entry['level'];
        $lines = ["The app logged {$name}: {$entry['message']}"];

        if ($entry['where'] !== null) {
            $lines[] = '';
            $lines[] = "Thrown at {$entry['where']}";
        }

        if ($entry['frames'] !== []) {
            $lines[] = '';
            array_push($lines, ...$entry['frames']);
        }

        $lines[] = '';
        $lines[] = 'laravel.log has the whole entry. Call allowAppErrors() in a test where the app is meant to log errors, or expectAppError('.($entry['class'] === null ? 'SomeException' : self::basename($entry['class'])).'::class) for one it is meant to throw.';

        return implode("\n", $lines);
    }

    private static function expectedMatch(?string $class): ?string
    {
        if ($class === null) {
            return null;
        }

        foreach (self::$expected as $expected) {
            if ($class === $expected || (class_exists($class) && is_a($class, $expected, true))) {
                return $expected;
            }
        }

        return null;
    }

    /**
     * Paths on the device from the app's own directory, as `app/…` and `vendor/…`. iOS
     * unpacks the app into `Documents/app/`, Android into `app_storage/laravel/`.
     */
    private static function shorten(string $text): string
    {
        return (string) preg_replace('#/[^\s()]*?/(?:Documents/app|app_storage/laravel)/#', '', $text);
    }

    private static function basename(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
