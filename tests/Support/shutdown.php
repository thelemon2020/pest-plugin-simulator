<?php

declare(strict_types=1);

// A run that defers two cleanup tasks, then ends the way $argv[2] names.

use NativePhp\Simulator\Shutdown;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $log, $ending] = $argv;

Shutdown::trap();
Shutdown::defer(function () use ($log): void {
    file_put_contents($log, "first\n", FILE_APPEND);
});
Shutdown::defer(function () use ($log, $ending): void {
    file_put_contents($log, "second\n", FILE_APPEND);

    if ($ending === 'signal-during-cleanup') {
        posix_kill(getmypid(), SIGTERM);
        file_put_contents($log, "rest of second\n", FILE_APPEND);
    }
});

match ($ending) {
    'terminate', 'signal-during-cleanup' => Shutdown::run(),
    'fatal' => throw new RuntimeException('The run crashed.'),
    'SIGINT' => posix_kill(getmypid(), SIGINT),
    'SIGTERM' => posix_kill(getmypid(), SIGTERM),
};

file_put_contents($log, "end of script\n", FILE_APPEND);
