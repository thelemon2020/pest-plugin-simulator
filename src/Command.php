<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

class Command
{
    /** Ample for a query, a tap, or a file copy. It only stops a call that is never coming back. */
    public const int TIMEOUT = 60;

    /**
     * simctl's device lifecycle. A cold boot on CI takes minutes, and the
     * first simctl call of a run also starts CoreSimulatorService.
     */
    public const int DEVICE_TIMEOUT = 600;

    /** `native:run`. NativePHP stops Gradle itself at 600 seconds. This also covers the install and launch. */
    public const int BUILD_TIMEOUT = 1800;

    /** Seconds between SIGTERM and SIGKILL for a command that ran out of time. */
    protected const float GRACE = 2.0;

    /** @var list<string> */
    private const array DEVICE_COMMANDS = ['list', 'boot', 'bootstatus', 'shutdown', 'erase', 'clone', 'delete'];

    /** The longest wait between two looks at a command's pipes. */
    private const float POLL = 0.02;

    /**
     * @param  list<string>  $arguments
     */
    public function run(string $binary, array $arguments, ?string $cwd = null, ?float $timeout = null): string
    {
        return $this->execute(array_merge([$binary], $arguments), null, $cwd, $timeout ?? self::limit($arguments));
    }

    /**
     * @param  list<string>  $arguments
     */
    public function input(string $binary, array $arguments, string $stdin, ?string $cwd = null, ?float $timeout = null): string
    {
        return $this->execute(array_merge([$binary], $arguments), $stdin, $cwd, $timeout ?? self::limit($arguments));
    }

    /**
     * How long a call may run before it is stopped, unless the caller says.
     *
     * @param  list<string>  $arguments
     */
    public static function limit(array $arguments): int
    {
        $first = $arguments[0] ?? null;
        $second = $arguments[1] ?? null;

        if ($first === 'simctl' && in_array($second, self::DEVICE_COMMANDS, true)) {
            return self::DEVICE_TIMEOUT;
        }

        if ($first === 'artisan' && $second === 'native:run') {
            return self::BUILD_TIMEOUT;
        }

        return self::TIMEOUT;
    }

    /**
     * @param  list<string>  $command
     */
    private function execute(array $command, ?string $stdin, ?string $cwd, float $timeout): string
    {
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        if ($stdin !== null) {
            $descriptor[0] = ['pipe', 'r'];
        }

        $started = self::now();
        $process = proc_open($command, $descriptor, $pipes, $cwd);

        if (! is_resource($process)) {
            $this->log($started, 'not run', $command);

            throw new SimulatorException('Could not run '.implode(' ', $command));
        }

        $deadline = $started + $timeout;
        $output = $this->exchange($process, $pipes, $stdin, $deadline);
        $exit = $output === null ? null : $this->exitCode($process, $deadline);

        if ($output === null || $exit === null) {
            $this->kill($process, $pipes);
            $this->log($started, 'timed out', $command);

            throw new SimulatorException(sprintf('%s did not finish within %s seconds, so it was stopped.', implode(' ', $command), $timeout));
        }

        proc_close($process);
        [$stdout, $stderr] = $output;
        $failure = trim($stderr !== '' ? $stderr : $stdout);
        $this->log($started, 'exit '.$exit, $command, $exit !== 0 ? $failure : '');

        if ($exit !== 0) {
            throw new SimulatorException($failure ?: 'Command failed: '.implode(' ', $command));
        }

        return $stdout;
    }

    /**
     * @param  list<string>  $command
     */
    private function log(float $started, string $status, array $command, string $failure = ''): void
    {
        if (VerboseLog::enabled()) {
            VerboseLog::write(self::now() - $started, $status, VerboseLog::command($command).($failure !== '' ? ': '.$failure : ''));
        }
    }

    /**
     * Feed stdin and read stdout and stderr all at once. Reading one pipe to
     * the end before the next stalls a child that fills the other pipe's
     * buffer, and then both sides wait on each other forever.
     *
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     * @return array{0: string, 1: string}|null null when the deadline passes first
     */
    private function exchange($process, array $pipes, ?string $stdin, float $deadline): ?array
    {
        $output = [1 => '', 2 => ''];
        $reading = [1 => $pipes[1], 2 => $pipes[2]];
        $writing = $pipes[0] ?? null;
        $pending = $stdin ?? '';

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        while (true) {
            // Look before reading: once it has exited, everything it wrote is
            // already in the pipes, and this pass reads it.
            $exited = ! proc_get_status($process)['running'];

            if ($writing !== null && $pending !== '') {
                $written = @fwrite($writing, $pending);
                $pending = $written === false ? '' : substr($pending, $written);
            }

            if ($writing !== null && ($pending === '' || $exited)) {
                fclose($writing);
                $writing = null;
            }

            foreach ($reading as $index => $pipe) {
                for ($chunks = 0; $chunks < 16; $chunks++) {
                    $chunk = fread($pipe, 65536);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    $output[$index] .= $chunk;
                }

                if ($chunk === false || feof($pipe)) {
                    fclose($pipe);
                    unset($reading[$index]);
                }
            }

            // A pipe can stay open after exit when something the command
            // started inherited it. The command itself is done.
            if (($reading === [] && $writing === null) || $exited) {
                foreach ($reading as $pipe) {
                    fclose($pipe);
                }

                return [$output[1], $output[2]];
            }

            $remaining = $deadline - self::now();

            if ($remaining <= 0) {
                return null;
            }

            // select() only shortens the wait. It returns early for a signal,
            // and never sees a pipe whose descriptor is past FD_SETSIZE, so
            // every pass reads every pipe regardless.
            $read = array_values($reading);
            $write = $writing === null ? [] : [$writing];
            $except = null;
            $wait = (int) (min($remaining, self::POLL) * 1_000_000);

            if ($read === [] && $write === []) {
                usleep($wait);
            } else {
                @stream_select($read, $write, $except, 0, $wait);
            }
        }
    }

    /**
     * @param  resource  $process
     */
    private function exitCode($process, float $deadline): ?int
    {
        $pause = 500;

        while (($status = proc_get_status($process))['running']) {
            if (self::now() >= $deadline) {
                return null;
            }

            usleep($pause);
            $pause = min($pause * 2, 10_000);
        }

        return $status['exitcode'];
    }

    /**
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     */
    private function kill($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        // What it started goes too. A timed-out native:run would otherwise
        // leave xcodebuild or Gradle building, and holding their locks.
        $pid = proc_get_status($process)['pid'];
        $tree = [$pid, ...$this->descendants($pid)];

        // SIGTERM, then SIGKILL. The SIG* constants need ext-pcntl.
        foreach ($tree as $each) {
            $this->signal($each, 15, 'kill -TERM ');
        }

        $grace = self::now() + static::GRACE;

        while (proc_get_status($process)['running'] && self::now() < $grace) {
            usleep(20_000);
        }

        foreach ($tree as $each) {
            if ($each === $pid ? proc_get_status($process)['running'] : $this->running($each)) {
                $this->signal($each, 9, 'kill -KILL ');
            }
        }

        $reaped = self::now() + 1.0;

        while (proc_get_status($process)['running']) {
            // SIGKILL waits while the process is stuck in the kernel, and
            // proc_close() would wait on that with no limit. Leave it.
            if (self::now() >= $reaped) {
                return;
            }

            usleep(10_000);
        }

        proc_close($process);
    }

    /**
     * @return list<int>
     */
    private function descendants(int $pid): array
    {
        $children = [];

        foreach (explode("\n", (string) shell_exec('ps -A -o pid= -o ppid= 2>/dev/null')) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (count($fields) === 2 && ctype_digit($fields[0]) && ctype_digit($fields[1])) {
                $children[(int) $fields[1]][] = (int) $fields[0];
            }
        }

        $found = [];
        $queue = [$pid];

        while ($queue !== []) {
            foreach ($children[array_shift($queue)] ?? [] as $child) {
                if (! in_array($child, $found, true) && $child !== $pid) {
                    $found[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $found;
    }

    /**
     * Deadlines follow a monotonic clock. A wall clock jumps when the
     * machine wakes from sleep, and would stop a healthy build.
     */
    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    /**
     * @param  list<string>  $arguments
     */
    public function start(string $binary, array $arguments, string $log): int
    {
        $command = array_merge([$binary], $arguments);
        $escaped = implode(' ', array_map('escapeshellarg', $command));
        $output = [];
        exec(sprintf('nohup %s >> %s 2>&1 & echo $!', $escaped, escapeshellarg($log)), $output);
        $pid = trim($output[0] ?? '');

        if ($pid === '' || ! ctype_digit($pid)) {
            $this->log(self::now(), 'not run', $command);

            throw new SimulatorException('Could not start '.$binary);
        }

        $this->log(self::now(), 'started', $command, 'pid '.$pid);

        return (int) $pid;
    }

    public function stop(int $pid): void
    {
        $this->signal($pid, SIGTERM, 'kill ');
    }

    public function interrupt(int $pid): void
    {
        $started = self::now();
        $this->signal($pid, SIGINT, 'kill -INT ');
        $stopped = $this->wait($pid);
        VerboseLog::write(self::now() - $started, $stopped ? 'stopped' : 'running', 'interrupt pid '.$pid);
    }

    public function wait(int $pid, float $seconds = 10): bool
    {
        if ($pid <= 0 || ! function_exists('posix_kill')) {
            return true;
        }

        $deadline = self::now() + $seconds;

        while (self::now() < $deadline) {
            if (! posix_kill($pid, 0)) {
                return true;
            }

            usleep(50_000);
        }

        return posix_kill($pid, 0) === false;
    }

    public function running(int $pid): bool
    {
        if ($pid <= 0 || ! function_exists('posix_kill')) {
            return false;
        }

        return @posix_kill($pid, 0);
    }

    private function signal(int $pid, int $signal, string $command): void
    {
        if ($pid <= 0) {
            return;
        }

        if (function_exists('posix_kill')) {
            posix_kill($pid, $signal);

            return;
        }

        exec($command.$pid.' >/dev/null 2>&1');
    }
}
