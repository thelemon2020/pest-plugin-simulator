<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\AppError;
use NativePhp\Simulator\Exceptions\SimulatorException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Throwable;

/**
 * What the app's PHP logged while a test drove it. An exception in a NativePHP component is
 * caught, handed to report(), and drawn as NativePHP's own error screen, so without this a
 * test only sees a screen that never shows what it waits for, and fails at the timeout.
 *
 * The test's first screen() notes where laravel.log ends. Each step reads what the app
 * wrote after that, and a wait reads it at most once a second, so an error ends the step
 * that is under way when it lands, and names the exception, its message, and where it was
 * thrown. A tap only sends the touch, so its error usually lands in the step after it. A
 * test whose last step is a tap or a keystroke reads the log once more as it ends.
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

    /** How long a test that ends on a tap waits for the app's PHP to answer it. */
    private const LAST_STEP = 1.0;

    private static bool $started = false;

    /** The first read failed, so where the log ended is not known yet. */
    private static bool $unknown = false;

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
        self::$unknown = false;
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
        self::mark($driver);
    }

    /**
     * Where the log ends now, read without its bytes. A read that fails leaves it unknown,
     * and the next check() tries again rather than reading the whole log from 0.
     */
    private static function mark(Driver $driver): void
    {
        try {
            $tail = $driver->tailAppFile(self::PATTERN, null);
        } catch (SimulatorException) {
            self::$unknown = true;

            return;
        }

        self::$unknown = false;
        self::$path = $tail['path'] ?? null;
        self::$offset = $tail['size'] ?? 0;
    }

    /**
     * The file the app's errors were read from, relative to its storage, once it has one.
     */
    public static function path(): ?string
    {
        return self::$started ? self::$path : null;
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

        if (self::$unknown) {
            self::mark($driver);

            return;
        }

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
     * The end of a test. A tap does not wait for the app, so a test whose last step was a
     * tap or a keystroke reads the log for a second more, for the error that tap caused.
     *
     * With expectAppError(), it reads the log until the error it named is there, for up to
     * the timeout, and fails a test whose expected error the app never logged.
     */
    public static function finish(Driver $driver, bool $endedOnInput = false): void
    {
        if (self::$expected === []) {
            if (self::$started && $endedOnInput) {
                // A suite that waits for nothing, with a timeout of 0, reads it once.
                $deadline = microtime(true) + min(self::LAST_STEP, Configuration::resolve()->timeout());

                do {
                    self::check($driver);

                    if (microtime(true) >= $deadline) {
                        break;
                    }

                    usleep(250_000);
                } while (true);
            }

            return;
        }

        if (! self::$started) {
            throw new AssertionFailedError(Configuration::resolve()->checksAppErrors()
                ? 'expectAppError() reads the log of the app the test drove, and this test never opened a screen().'
                : 'expectAppError() cannot see the app\'s errors while app_errors is turned off.');
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
     * A step that failed may have failed because the app's PHP did. The log is read once
     * more, whenever it was last read, and an error there is what the step reports, with
     * the step's own failure after it.
     */
    public static function explain(Driver $driver, Throwable $failure): Throwable
    {
        if (! self::$started || $failure instanceof AppError) {
            return $failure;
        }

        try {
            self::check($driver);
        } catch (AppError $error) {
            return new AppError($error->getMessage()."\n\nThe step had failed first: ".$failure->getMessage(), $error->class, $failure);
        } catch (Throwable) {
        }

        return $failure;
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
        $body = '';
        $stack = false;

        foreach ($lines as $line) {
            if (preg_match('/^\[\d{4}-\d{2}-\d{2}[ T][^\]]*\] [\w-]+\.([A-Z]+): ?(.*)$/', $line, $match) === 1) {
                if ($current !== null) {
                    $entries[] = self::complete($current, $body);
                }

                $current = ['level' => $match[1], 'header' => $line, 'message' => '', 'class' => null, 'where' => null, 'frames' => []];
                $body = $match[2];
                $stack = false;

                continue;
            }

            if ($current === null) {
                continue;
            }

            if ($line === '[stacktrace]') {
                $stack = true;

                continue;
            }

            if ($stack || preg_match('/^#\d+ /', $line) === 1) {
                $stack = true;

                if (count($current['frames']) < self::FRAMES && preg_match('/^#\d+ /', $line) === 1) {
                    $current['frames'][] = self::shorten(stripslashes($line));
                }

                continue;
            }

            // A message, or the context after it, written over more than one line.
            $body .= "\n".$line;
        }

        if ($current !== null) {
            $entries[] = self::complete($current, $body);
        }

        return $entries;
    }

    /**
     * @return list<Entry>
     */
    private static function read(Driver $driver): array
    {
        try {
            $tail = $driver->tailAppFile(self::PATTERN, self::$path === null ? 0 : self::$offset, self::$path);
        } catch (SimulatorException) {
            // The next read picks up from the same place.
            return [];
        }

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
     * The message and the exception, from all the entry's text before its stack. Laravel
     * puts the report's own context, such as `userId`, ahead of `exception` in the JSON
     * after the message, and a message can run over more than one line.
     *
     * @param  Entry  $entry
     * @return Entry
     */
    private static function complete(array $entry, string $body): array
    {
        $marker = '"exception":"[object] (';
        $at = strpos($body, $marker);
        $message = $body;

        if ($at !== false) {
            $json = strrpos(substr($body, 0, $at), ' {');
            $message = $json === false ? substr($body, 0, $at) : substr($body, 0, $json);
            $exception = stripslashes(substr($body, $at + strlen($marker)));

            if (preg_match('/^([A-Za-z_][\w\\\\]*)\(code: [^)]*\): (.*) at (\S+):(\d+)\)/s', $exception, $match) === 1) {
                $entry['class'] = $match[1];
                $message = $match[2];
                $entry['where'] = self::shorten($match[3]).':'.$match[4];
            }
        }

        $entry['message'] = trim($message);

        return $entry;
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
        $lines[] = (self::$path === null ? 'laravel.log' : basename(self::$path)).' has the whole entry. Call allowAppErrors() in a test where the app is meant to log errors, or expectAppError('.($entry['class'] === null ? 'SomeException' : self::basename($entry['class'])).'::class) for one it is meant to throw.';

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
