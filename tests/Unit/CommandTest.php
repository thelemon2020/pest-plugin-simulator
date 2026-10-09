<?php

declare(strict_types=1);

use NativePhp\Simulator\Command;
use NativePhp\Simulator\Exceptions\SimulatorException;

beforeEach(function () {
    $this->pidFile = tempnam(sys_get_temp_dir(), 'pest-simulator-pid-');
});

afterEach(function () {
    @unlink($this->pidFile);
});

it('reads stdout while a child fills stderr past the pipe buffer', function () {
    // Draining stdout first deadlocked here: the child blocks writing stderr
    // and never closes stdout. The timeout turns a regression into a failure.
    $output = (new Command)->run(PHP_BINARY, [
        '-r', 'fwrite(STDERR, str_repeat("e", 200000)); echo str_repeat("o", 200000);',
    ], timeout: 10);

    expect(strlen($output))->toBe(200000);
});

it('reports stderr past the pipe buffer when the child fails', function () {
    $run = fn () => (new Command)->run(PHP_BINARY, [
        '-r', 'fwrite(STDERR, str_repeat("e", 200000)); exit(1);',
    ], timeout: 10);

    expect($run)->toThrow(SimulatorException::class, str_repeat('e', 200000));
});

it('feeds stdin while the child is still writing stdout', function () {
    $input = str_repeat("line of text\n", 20000);

    expect((new Command)->input('cat', [], $input, timeout: 10))->toBe($input);
});

it('stops a command that runs past its timeout and says which', function () {
    $started = microtime(true);
    $run = fn () => (new Command)->run(PHP_BINARY, [
        '-r', 'file_put_contents($argv[1], getmypid()); sleep(30);', $this->pidFile,
    ], timeout: 0.3);

    expect($run)->toThrow(SimulatorException::class, PHP_BINARY.' -r file_put_contents($argv[1], getmypid()); sleep(30); '.$this->pidFile.' did not finish within 0.3 seconds, so it was stopped.')
        ->and(microtime(true) - $started)->toBeLessThan(2.0)
        ->and(processRunning((int) file_get_contents($this->pidFile)))->toBeFalse();
});

it('kills a command that ignores SIGTERM once its grace period is up', function () {
    $command = new class extends Command
    {
        protected const float GRACE = 0.2;
    };
    $started = microtime(true);
    $run = fn () => $command->run(PHP_BINARY, [
        '-r', 'pcntl_signal(SIGTERM, SIG_IGN); file_put_contents($argv[1], getmypid()); sleep(30);', $this->pidFile,
    ], timeout: 0.4);

    expect($run)->toThrow(SimulatorException::class, 'did not finish within 0.4 seconds')
        ->and(microtime(true) - $started)->toBeGreaterThan(0.6)
        ->and(processRunning((int) file_get_contents($this->pidFile)))->toBeFalse();
})->skip(! function_exists('pcntl_signal'), 'Needs ext-pcntl.');

function processRunning(int $pid): bool
{
    return $pid > 0 && posix_kill($pid, 0);
}
