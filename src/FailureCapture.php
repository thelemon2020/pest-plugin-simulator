<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;
use Error;
use Exception;
use NativePhp\Simulator\Exceptions\CommandTimedOut;
use NativePhp\Simulator\Exceptions\CompanionUnresponsive;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionProperty;
use Throwable;

/**
 * What a failed test leaves in simulator-failures/: the trace, the tree, a screenshot, and
 * the app's logs. A failed assertion saves them from Screen. A test that ends with an error
 * instead, such as a device call that timed out, saves them on its way out.
 */
final class FailureCapture
{
    /**
     * Save what a test that ended with this error leaves behind, and name the files at the
     * end of its message. A failed assertion has saved its own. A test that never reached
     * the device has no trace, and nothing on screen of its own.
     */
    public static function error(Throwable $error): void
    {
        if ($error instanceof AssertionFailedError || Trace::steps() === [] || ! Run::inside()) {
            return;
        }

        try {
            $root = self::directory();
            $saved = self::save(Sessions::get(Run::device()), $root, $error);
        } catch (Throwable) {
            return;
        }

        if ($saved !== []) {
            self::append($error, self::listing($root, $saved));
        }
    }

    /**
     * A directory of its own under simulator-failures/, named for when the test failed. Two
     * failures in the same second, in one worker or two, each get their own.
     */
    public static function directory(): string
    {
        $stamp = getcwd().'/simulator-failures/'.date('Ymd-His');

        for ($attempt = 1; ; $attempt++) {
            $root = $attempt === 1 ? $stamp : "{$stamp}-{$attempt}";

            // Another worker can take the name between the look and the mkdir.
            if (! is_dir($root) && (@mkdir($root, 0777, true) || ! is_dir($root))) {
                return $root;
            }
        }
    }

    /**
     * The trace goes first: it needs nothing from the device, and is on disk even if a read
     * after it is cut short.
     *
     * A device that has stopped answering is not asked again, since asking only waits out
     * the same silence. When the companion stopped, the tree is skipped: it comes from the
     * companion on iOS, and the screenshot and logs come from simctl. When a command ran out
     * of time, simctl or adb is the one stuck, so nothing is read.
     *
     * @return list<string> the files saved
     */
    public static function save(Driver $driver, string $root, ?Throwable $cause = null): array
    {
        if (! is_dir($root)) {
            @mkdir($root, 0777, true);
        }

        $saved = Trace::write($root);

        if (self::causedBy($cause, CommandTimedOut::class)) {
            return $saved;
        }

        if (! self::causedBy($cause, CompanionUnresponsive::class)) {
            self::attempt(fn () => $driver->describe($root.'/tree.json'));

            if (is_file($root.'/tree.json')) {
                $saved[] = $root.'/tree.json';
            }
        }

        self::attempt(fn () => $driver->screenshot($root.'/screen.png'));

        if (is_file($root.'/screen.png')) {
            $saved[] = $root.'/screen.png';
        }

        self::attempt(function () use ($driver, $root, &$saved): void {
            foreach ($driver->captureLogs($root) as $path) {
                if (is_file($path)) {
                    $saved[] = $path;
                }
            }
        });

        return $saved;
    }

    /**
     * The files saved, as the end of a failure message.
     *
     * @param  list<string>  $saved
     */
    public static function listing(string $root, array $saved): string
    {
        if ($saved === []) {
            return "\n\nSaved {$root}";
        }

        return "\n\n".implode("\n", array_map(
            fn (string $path): string => "Saved {$path}",
            $saved,
        ));
    }

    /**
     * @param  Closure(): mixed  $read
     */
    private static function attempt(Closure $read): void
    {
        try {
            $read();
        } catch (Throwable) {
        }
    }

    /**
     * @param  class-string<Throwable>  $class
     */
    private static function causedBy(?Throwable $error, string $class): bool
    {
        for (; $error !== null; $error = $error->getPrevious()) {
            if ($error instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The test still fails with the error it threw, of the same class, from the same line.
     * Only its message grows.
     */
    private static function append(Throwable $error, string $text): void
    {
        (new ReflectionProperty($error instanceof Exception ? Exception::class : Error::class, 'message'))
            ->setValue($error, $error->getMessage().$text);
    }
}
