<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

class Command
{
    public function run(string $binary, array $arguments, ?string $cwd = null): string
    {
        $command = array_merge([$binary], $arguments);
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes, $cwd);

        if (! is_resource($process)) {
            throw new SimulatorException('Could not run '.implode(' ', $command));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            throw new SimulatorException(trim($stderr !== false && $stderr !== '' ? $stderr : (string) $stdout) ?: 'Command failed: '.implode(' ', $command));
        }

        return $stdout === false ? '' : $stdout;
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
