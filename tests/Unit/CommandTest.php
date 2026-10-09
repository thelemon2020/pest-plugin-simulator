<?php

declare(strict_types=1);

use NativePhp\Simulator\Command;
use NativePhp\Simulator\Exceptions\SimulatorException;

beforeEach(function () {
    $this->pidFile = tempnam(sys_get_temp_dir(), 'pest-simulator-pid-');
    $this->strays = [];
});

afterEach(function () {
    foreach ($this->strays as $pid) {
        if ((new Command)->running($pid)) {
            posix_kill($pid, SIGKILL);
        }
    }

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
    $run = fn () => (new Command)->run('sh', ['-c', 'echo $$ > "$1"; exec sleep 30', 'sh', $this->pidFile], timeout: 0.3);

    expect($run)->toThrow(SimulatorException::class, 'sh -c echo $$ > "$1"; exec sleep 30 sh '.$this->pidFile.' did not finish within 0.3 seconds, so it was stopped.');

    $pid = (int) file_get_contents($this->pidFile);

    expect($pid)->toBeGreaterThan(0)
        ->and(microtime(true) - $started)->toBeLessThan(2.0)
        ->and((new Command)->running($pid))->toBeFalse();
});

it('kills a command that ignores SIGTERM once its grace period is up', function () {
    $command = new class extends Command
    {
        protected const float GRACE = 0.2;
    };
    $started = microtime(true);
    // The trap is in place before the pid is written, and sleep inherits it.
    $run = fn () => $command->run('sh', ['-c', 'trap "" TERM; echo $$ > "$1"; exec sleep 30', 'sh', $this->pidFile], timeout: 0.3);

    expect($run)->toThrow(SimulatorException::class, 'did not finish within 0.3 seconds');

    $pid = (int) file_get_contents($this->pidFile);

    expect($pid)->toBeGreaterThan(0)
        ->and(microtime(true) - $started)->toBeGreaterThan(0.5)
        ->and((new Command)->running($pid))->toBeFalse();
});

it('stops what a timed-out command started, not just the command', function () {
    $run = fn () => (new Command)->run('sh', ['-c', 'sleep 30 & echo $! > "$1"; wait', 'sh', $this->pidFile], timeout: 0.3);

    expect($run)->toThrow(SimulatorException::class, 'did not finish within 0.3 seconds');

    $this->strays[] = $grandchild = (int) file_get_contents($this->pidFile);

    expect($grandchild)->toBeGreaterThan(0)
        ->and(eventually(fn (): bool => ! (new Command)->running($grandchild)))->toBeTrue();
});

it('returns once the command exits, even while something it started holds the pipe', function () {
    $started = microtime(true);
    $output = (new Command)->run('sh', ['-c', 'sleep 30 & echo $!'], timeout: 10);
    $this->strays[] = (int) trim($output);

    expect(trim($output))->toMatch('/^\d+$/')
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
});

it('reads its pipes when their descriptors are past what select() can watch', function () {
    $script = dirname(__DIR__).'/Support/descriptors.php';
    exec('(ulimit -n 4096 2>/dev/null || exit 77; '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).') 2>&1', $output, $code);

    if ($code === 77) {
        $this->markTestSkipped('Could not raise the open file limit.');
    }

    expect(implode("\n", $output))->toBe('answered')
        ->and($code)->toBe(0);
});

it('gives the simulator lifecycle and native:run longer than everything else', function () {
    expect(Command::limit(['simctl', 'bootstatus', 'UDID', '-b']))->toBe(Command::DEVICE_TIMEOUT)
        ->and(Command::limit(['simctl', 'list', 'devices', 'available', '-j']))->toBe(Command::DEVICE_TIMEOUT)
        ->and(Command::limit(['simctl', 'shutdown', 'UDID']))->toBe(Command::DEVICE_TIMEOUT)
        ->and(Command::limit(['artisan', 'native:run', 'ios', 'UDID', '--build=debug', '--no-tty']))->toBe(Command::BUILD_TIMEOUT)
        ->and(Command::limit(['simctl', 'openurl', 'UDID', 'app://']))->toBe(Command::TIMEOUT)
        ->and(Command::limit(['-s', 'emulator-5554', 'shell', 'input', 'tap', '1', '2']))->toBe(Command::TIMEOUT);
});

function eventually(Closure $condition, float $seconds = 2.0): bool
{
    $deadline = microtime(true) + $seconds;

    while (! $condition()) {
        if (microtime(true) >= $deadline) {
            return false;
        }

        usleep(20_000);
    }

    return true;
}
