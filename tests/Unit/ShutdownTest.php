<?php

declare(strict_types=1);

use NativePhp\Simulator\Shutdown;

beforeEach(function () {
    $this->log = tempnam(sys_get_temp_dir(), 'pest-simulator-shutdown-');
});

afterEach(function () {
    @unlink($this->log);
    Shutdown::reset();
});

it('runs each task once when the run is terminated and then exits', function () {
    expect(endRun($this->log, 'terminate'))->toBe(0)
        ->and(file_get_contents($this->log))->toBe("second\nfirst\nend of script\n");
});

it('runs the tasks when the run dies of a fatal error', function () {
    expect(endRun($this->log, 'fatal'))->toBe(255)
        ->and(file_get_contents($this->log))->toBe("second\nfirst\n");
});

it('runs the tasks on a signal, unblocked, and exits with its conventional code', function (string $signal, int $code) {
    expect(endRun($this->log, $signal))->toBe($code)
        ->and(file_get_contents($this->log))->toBe("second\na command started here blocks 0 signals\nfirst\n");
})->with([
    'Ctrl+C' => ['SIGINT', 130],
    'SIGTERM' => ['SIGTERM', 143],
])->skip(! function_exists('pcntl_async_signals'), 'Needs ext-pcntl.');

it('finishes the remaining tasks when a signal lands mid-cleanup, without repeating one', function () {
    expect(endRun($this->log, 'signal-during-cleanup'))->toBe(143)
        ->and(file_get_contents($this->log))->toBe("second\nfirst\n");
})->skip(! function_exists('pcntl_async_signals'), 'Needs ext-pcntl.');

it('leaves signal handling alone until there is something to clean up', function () {
    expect(endRun($this->log, 'handlers'))->toBe(0)
        ->and(file_get_contents($this->log))->toBe(
            "before defer: SIGINT default, SIGTERM default\n".
            "after defer: SIGINT handled, SIGTERM handled\n".
            "second\nfirst\nend of script\n",
        );
})->skip(! function_exists('pcntl_async_signals'), 'Needs ext-pcntl.');

it('keeps a SIGINT handler that was already there, and still cleans up when it exits', function () {
    expect(endRun($this->log, 'after-phpunit-sigint'))->toBe(2)
        ->and(file_get_contents($this->log))->toBe(
            "phpunit\nsecond\na command started here blocks 0 signals\nfirst\n",
        );
})->skip(! function_exists('pcntl_async_signals'), 'Needs ext-pcntl.');

it('runs a task once however many times it is asked to run', function () {
    $runs = 0;
    Shutdown::defer(function () use (&$runs): void {
        $runs++;
    });

    Shutdown::run();
    Shutdown::run();

    expect($runs)->toBe(1);
});

function endRun(string $log, string $ending): int
{
    $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, dirname(__DIR__).'/Support/shutdown.php', $log, $ending]));
    exec('('.$command.' >/dev/null 2>&1; echo $?) 2>/dev/null', $output);

    return (int) end($output);
}
