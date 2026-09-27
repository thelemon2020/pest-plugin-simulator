<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;
use Throwable;

final class Shutdown
{
    /** @var list<Closure(): void> */
    private static array $tasks = [];

    public static function defer(Closure $task): void
    {
        self::$tasks[] = $task;
    }

    public static function run(): void
    {
        $tasks = array_reverse(self::$tasks);
        self::$tasks = [];

        foreach ($tasks as $task) {
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
}
