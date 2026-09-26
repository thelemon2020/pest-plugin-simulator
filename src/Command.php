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
    public function start(string $binary, array $arguments, string $log): void
    {
        $command = array_merge([$binary], $arguments);
        $escaped = implode(' ', array_map('escapeshellarg', $command));

        exec(sprintf('nohup %s >> %s 2>&1 &', $escaped, escapeshellarg($log)));
    }
}
