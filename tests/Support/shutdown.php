<?php

declare(strict_types=1);

// A run that defers two cleanup tasks, then ends the way $argv[2] names.

use NativePhp\Simulator\Command;
use NativePhp\Simulator\Shutdown;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $log, $ending] = $argv;

$write = function (string $line) use ($log): void {
    file_put_contents($log, $line."\n", FILE_APPEND);
};
$handler = fn (int $signal): string => is_int(pcntl_signal_get_handler($signal)) ? 'default' : 'handled';

if ($ending === 'handlers') {
    $write('before defer: SIGINT '.$handler(SIGINT).', SIGTERM '.$handler(SIGTERM));
}

if ($ending === 'after-phpunit-sigint') {
    // Stands in for PHPUnit's handler, which exits on a second Ctrl+C.
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use ($write): void {
        $write('phpunit');
        exit(2);
    });
}

Shutdown::defer(function () use ($write): void {
    $write('first');
});
Shutdown::defer(function () use ($write, $ending): void {
    $write('second');

    if ($ending === 'signal-during-cleanup') {
        posix_kill(getmypid(), SIGTERM);
        $write('rest of second');
    }

    if (in_array($ending, ['SIGINT', 'SIGTERM', 'after-phpunit-sigint'], true)) {
        $blocked = (new Command)->run(PHP_BINARY, ['-r', 'pcntl_sigprocmask(SIG_BLOCK, [SIGUSR2], $old); echo count($old);']);
        $write('a command started here blocks '.$blocked.' signals');
    }
});

if ($ending === 'handlers') {
    $write('after defer: SIGINT '.$handler(SIGINT).', SIGTERM '.$handler(SIGTERM));
}

match ($ending) {
    'terminate', 'signal-during-cleanup', 'handlers' => Shutdown::run(),
    'fatal' => throw new RuntimeException('The run crashed.'),
    'SIGINT', 'after-phpunit-sigint' => posix_kill(getmypid(), SIGINT),
    'SIGTERM' => posix_kill(getmypid(), SIGTERM),
};

$write('end of script');
