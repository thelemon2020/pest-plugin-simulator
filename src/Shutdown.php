<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;
use Throwable;

final class Shutdown
{
    /** @var list<Closure(): void> */
    private static array $tasks = [];

    private static bool $trapped = false;

    public static function defer(Closure $task): void
    {
        self::$tasks[] = $task;
    }

    /**
     * Pest's terminate hook only runs when the suite ends on its own. A fatal
     * error or Ctrl+C would leave idb_companion, emulators, and recordings
     * running, so run the tasks then too.
     *
     * A run in one process swaps in PHPUnit's own SIGINT handler once tests
     * start. It stops after the current test, and terminate runs the tasks.
     */
    public static function trap(): void
    {
        if (self::$trapped) {
            return;
        }

        self::$trapped = true;
        register_shutdown_function(self::run(...));

        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, self::interrupted(...));
        pcntl_signal(SIGTERM, self::interrupted(...));
    }

    public static function run(): void
    {
        // One at a time: a signal that lands mid-cleanup runs this again, and
        // that call picks up at the next task instead of repeating any.
        while (($task = array_pop(self::$tasks)) !== null) {
            try {
                $task();
            } catch (Throwable) {
            }
        }
    }

    public static function reset(): void
    {
        self::$tasks = [];
    }

    private static function interrupted(int $signal): void
    {
        // PHP blocks every signal while a handler runs, and the commands the
        // tasks start would inherit that. Unblock them, so a timed-out command
        // can still be stopped, and a second Ctrl+C stops the cleanup itself.
        pcntl_signal(SIGINT, SIG_DFL);
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_sigprocmask(SIG_SETMASK, []);

        self::run();

        // Die by the signal rather than exit(). exit() runs destructors, and
        // in ParaTest's parent one SIGKILLs the workers mid-cleanup.
        if (function_exists('posix_kill')) {
            posix_kill(getmypid(), $signal);
        }

        exit(128 + $signal);
    }
}
