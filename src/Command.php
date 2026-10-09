<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

class Command
{
    /** Ample for a query, a tap, or a file copy. It only stops a call that is never coming back. */
    public const int TIMEOUT = 60;

    /** Booting, shutting down, erasing, cloning, or deleting a Simulator. A cold boot on CI takes minutes. */
    public const int DEVICE_TIMEOUT = 600;

    /** `native:run`. NativePHP stops Gradle itself at 600 seconds. This also covers the install and launch. */
    public const int BUILD_TIMEOUT = 1800;

    /** Seconds between SIGTERM and SIGKILL for a command that ran out of time. */
    protected const float GRACE = 2.0;

    public function run(string $binary, array $arguments, ?string $cwd = null, float $timeout = self::TIMEOUT): string
    {
        return $this->execute(array_merge([$binary], $arguments), null, $cwd, $timeout);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function input(string $binary, array $arguments, string $stdin, ?string $cwd = null, float $timeout = self::TIMEOUT): string
    {
        return $this->execute(array_merge([$binary], $arguments), $stdin, $cwd, $timeout);
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

        $process = proc_open($command, $descriptor, $pipes, $cwd);

        if (! is_resource($process)) {
            throw new SimulatorException('Could not run '.implode(' ', $command));
        }

        $deadline = microtime(true) + $timeout;
        $output = $this->exchange($pipes, $stdin, $deadline);
        $exit = $output === null ? null : $this->exitCode($process, $deadline);

        if ($output === null || $exit === null) {
            $this->kill($process);

            throw new SimulatorException(sprintf('%s did not finish within %s seconds, so it was stopped.', implode(' ', $command), $timeout));
        }

        proc_close($process);
        [$stdout, $stderr] = $output;

        if ($exit !== 0) {
            throw new SimulatorException(trim($stderr !== '' ? $stderr : $stdout) ?: 'Command failed: '.implode(' ', $command));
        }

        return $stdout;
    }

    /**
     * Feed stdin and read stdout and stderr all at once. Reading one pipe to
     * the end before the next stalls a child that fills the other pipe's
     * buffer, and then both sides wait on each other forever.
     *
     * @param  array<int, resource>  $pipes
     * @return array{0: string, 1: string}|null null when the deadline passes first
     */
    private function exchange(array $pipes, ?string $stdin, float $deadline): ?array
    {
        $output = [1 => '', 2 => ''];
        $reading = [1 => $pipes[1], 2 => $pipes[2]];
        $writing = $pipes[0] ?? null;
        $pending = $stdin ?? '';

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        if ($writing !== null && $pending === '') {
            fclose($writing);
            $writing = null;
        }

        while ($reading !== [] || $writing !== null) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                return null;
            }

            $read = array_values($reading);
            $write = $writing === null ? [] : [$writing];
            $except = null;

            // false means a signal interrupted the wait. Wait again.
            if (@stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1) * 1_000_000)) === false) {
                continue;
            }

            if ($write !== [] && $writing !== null) {
                $written = @fwrite($writing, $pending);
                $pending = $written === false ? '' : substr($pending, $written);

                if ($pending === '') {
                    fclose($writing);
                    $writing = null;
                }
            }

            foreach ($read as $pipe) {
                $index = array_search($pipe, $reading, true);
                $chunk = fread($pipe, 65536);

                if ($chunk !== false) {
                    $output[$index] .= $chunk;
                }

                if ($chunk === false || feof($pipe)) {
                    fclose($pipe);
                    unset($reading[$index]);
                }
            }
        }

        return [$output[1], $output[2]];
    }

    /**
     * @param  resource  $process
     */
    private function exitCode($process, float $deadline): ?int
    {
        while (($status = proc_get_status($process))['running']) {
            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep(5_000);
        }

        return $status['exitcode'];
    }

    /**
     * @param  resource  $process
     */
    private function kill($process): void
    {
        // SIGTERM, then SIGKILL. The SIG* constants need ext-pcntl.
        proc_terminate($process, 15);
        $grace = microtime(true) + static::GRACE;

        while (proc_get_status($process)['running'] && microtime(true) < $grace) {
            usleep(20_000);
        }

        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }

        proc_close($process);
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
            throw new SimulatorException('Could not start '.$binary);
        }

        return (int) $pid;
    }

    public function stop(int $pid): void
    {
        $this->signal($pid, SIGTERM, 'kill ');
    }

    public function interrupt(int $pid): void
    {
        $this->signal($pid, SIGINT, 'kill -INT ');
        $this->wait($pid);
    }

    public function wait(int $pid, float $seconds = 10): bool
    {
        if ($pid <= 0 || ! function_exists('posix_kill')) {
            return true;
        }

        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
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
