<?php

declare(strict_types=1);

use NativePhp\Simulator\Arguments;
use NativePhp\Simulator\Command;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Grpc\Client;
use NativePhp\Simulator\Plugin;
use NativePhp\Simulator\VerboseLog;
use Tests\Support\StubCompanion;

beforeEach(function () {
    $this->log = sys_get_temp_dir().'/simulator-verbose-'.uniqid('', true).'.log';
    // This suite can itself run in a parallel worker. The tests below say which process they are.
    $this->token = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN');
});

afterEach(function () {
    Arguments::reset();
    VerboseLog::to(null);
    putenv(is_string($this->token) ? 'TEST_TOKEN='.$this->token : 'TEST_TOKEN');

    if (isset($this->stub)) {
        $this->stub->stop();
    }

    if (is_file($this->log)) {
        unlink($this->log);
    }
});

it('strips --simulator-verbose and starts a log for the run', function () {
    file_put_contents($this->log, "an older run\n");

    $remaining = (new Plugin)->handleArguments(['vendor/bin/pest', '--simulator-verbose='.$this->log, 'tests/Feature/LightsTest.php']);

    expect($remaining)->toBe(['vendor/bin/pest', 'tests/Feature/LightsTest.php'])
        ->and(VerboseLog::path())->toBe($this->log)
        ->and(file_get_contents($this->log))->not->toContain('an older run')
        ->toContain('pest run: vendor/bin/pest --simulator-verbose='.$this->log.' tests/Feature/LightsTest.php');
});

it('writes simulator-logs/verbose.log when no path is given', function () {
    $directory = sys_get_temp_dir().'/simulator-verbose-'.uniqid('', true);
    mkdir($directory);
    $previous = getcwd();
    chdir($directory);

    try {
        Arguments::intercept(['--simulator-verbose']);
        $expected = getcwd().'/simulator-logs/verbose.log';

        expect(VerboseLog::path())->toBe($expected)
            ->and(is_file($expected))->toBeTrue();
    } finally {
        chdir((string) $previous);
        unlink($directory.'/simulator-logs/verbose.log');
        rmdir($directory.'/simulator-logs');
        rmdir($directory);
    }
});

it('hands the log to a parallel worker, which adds to it under its own index', function () {
    Arguments::intercept(['--simulator-verbose='.$this->log]);
    VerboseLog::to(null);
    putenv('TEST_TOKEN=3');

    // A worker's own arguments no longer carry the flag. It reads what the run published.
    Arguments::intercept(['vendor/bin/pest']);
    VerboseLog::note('from the worker');

    expect(VerboseLog::path())->toBe($this->log)
        ->and(file_get_contents($this->log))->toContain('pest run:')
        ->toMatch('/\d\d:\d\d:\d\d\.\d{3} \[w3\]\s+from the worker/');
});

it('does not start the log again in a worker that sees the flag', function () {
    file_put_contents($this->log, "the run so far\n");
    putenv('TEST_TOKEN=2');

    Arguments::intercept(['--simulator-verbose='.$this->log]);

    expect(file_get_contents($this->log))->toBe("the run so far\n");
});

it('logs nothing without the flag', function () {
    Arguments::intercept(['vendor/bin/pest']);

    (new Command)->run(PHP_BINARY, ['-r', 'echo 1;'], timeout: 10);

    expect(VerboseLog::enabled())->toBeFalse()
        ->and(is_file($this->log))->toBeFalse();
});

it('logs each command with its duration and exit status', function () {
    VerboseLog::to($this->log);

    (new Command)->run(PHP_BINARY, ['-r', 'usleep(100000);'], timeout: 10);

    expect(fn () => (new Command)->run(PHP_BINARY, ['-r', 'fwrite(STDERR, "no such device\n"); exit(3);'], timeout: 10))
        ->toThrow(SimulatorException::class, 'no such device');

    $lines = file($this->log, FILE_IGNORE_NEW_LINES) ?: [];

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toMatch('/\[main\]\s+\d+\.\d{3}s\s+exit 0\s+'.preg_quote(VerboseLog::command([PHP_BINARY, '-r', 'usleep(100000);']), '/').'$/')
        ->and(loggedSeconds($lines[0]))->toBeGreaterThanOrEqual(0.1)
        ->and($lines[1])->toMatch('/s\s+exit 3\s+.+: no such device$/');
});

it('logs a command stopped at its timeout', function () {
    VerboseLog::to($this->log);

    expect(fn () => (new Command)->run('sleep', ['5'], timeout: 0.2))->toThrow(SimulatorException::class);

    $line = (string) file_get_contents($this->log);

    expect($line)->toMatch('/s\s+timed out\s+sleep 5\n$/')
        ->and(loggedSeconds($line))->toBeGreaterThanOrEqual(0.2);
});

it('logs each companion call with its duration and grpc status', function () {
    $this->stub = StubCompanion::start([
        ['messages' => ['tree']],
        ['messages' => []],
        ['status' => 2, 'message' => 'no%20application'],
    ]);
    VerboseLog::to($this->log);
    $client = new Client($this->stub->url());

    $client->unary('accessibility_info', 'request');
    $client->streamPaced('hid', ['down', 'up'], 50_000);

    expect(fn () => $client->unary('accessibility_info', 'request'))->toThrow(SimulatorException::class);

    $lines = file($this->log, FILE_IGNORE_NEW_LINES) ?: [];

    expect($lines)->toHaveCount(3)
        ->and($lines[0])->toMatch('/s\s+grpc 0\s+companion accessibility_info$/')
        ->and($lines[1])->toMatch('/s\s+grpc 0\s+companion hid \(2 messages, 50ms apart\)$/')
        ->and($lines[2])->toMatch('/s\s+grpc 2\s+companion accessibility_info: Companion \[accessibility_info\] failed: no application$/');
});

it('logs a companion call that never answered', function () {
    $this->stub = StubCompanion::start([['hang' => true]]);
    VerboseLog::to($this->log);

    expect(fn () => (new Client($this->stub->url(), timeoutSeconds: 0.3))->unary('accessibility_info', 'request'))
        ->toThrow(SimulatorException::class);

    expect(file_get_contents($this->log))->toMatch('/s\s+timed out\s+companion accessibility_info: Companion \[accessibility_info\] did not answer in time/');
});

function loggedSeconds(string $line): float
{
    preg_match('/\]\s+(\d+\.\d{3})s\s/', $line, $match);

    return (float) ($match[1] ?? 0);
}
