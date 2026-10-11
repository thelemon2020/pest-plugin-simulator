<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

/**
 * A command Command::open() started and left running, to write to its stdin and read its
 * stdout while it runs. Every read waits on stream_select() up to a deadline, so a process
 * that stops answering cannot hang the test, and a signal still gets through.
 */
final class RunningCommand
{
    private string $buffer = '';

    /**
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     */
    public function __construct(
        private $process,
        private array $pipes,
    ) {}

    /**
     * False when the process has stopped reading.
     */
    public function write(string $text): bool
    {
        $stdin = $this->pipes[0] ?? null;

        if (! is_resource($stdin)) {
            return false;
        }

        while ($text !== '') {
            $written = @fwrite($stdin, $text);

            if ($written === false || $written === 0) {
                return false;
            }

            $text = substr($text, $written);
        }

        return @fflush($stdin);
    }

    /**
     * The next line, without its newline. Null when the process ends or the time runs out
     * first.
     */
    public function line(float $seconds): ?string
    {
        $deadline = microtime(true) + $seconds;

        while (($end = strpos($this->buffer, "\n")) === false) {
            if (! $this->fill($deadline)) {
                return null;
            }
        }

        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 1);

        return rtrim($line, "\r");
    }

    /**
     * Exactly $length bytes. Null when the process ends or the time runs out first.
     */
    public function bytes(int $length, float $seconds): ?string
    {
        $deadline = microtime(true) + $seconds;

        while (strlen($this->buffer) < $length) {
            if (! $this->fill($deadline)) {
                return null;
            }
        }

        $bytes = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $bytes;
    }

    /**
     * Close stdin, which tells a well-behaved process to finish, and stop it if it has not
     * within two seconds.
     */
    public function close(): void
    {
        if (! is_resource($this->process)) {
            return;
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        $this->pipes = [];
        $deadline = microtime(true) + 2.0;

        while (microtime(true) < $deadline && (proc_get_status($this->process)['running'] ?? false)) {
            usleep(20_000);
        }

        if (proc_get_status($this->process)['running'] ?? false) {
            proc_terminate($this->process);
        }

        proc_close($this->process);
    }

    private function fill(float $deadline): bool
    {
        $stdout = $this->pipes[1] ?? null;

        while (is_resource($stdout)) {
            $left = $deadline - microtime(true);

            if ($left <= 0) {
                return false;
            }

            $read = [$stdout];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int) $left, (int) (($left - floor($left)) * 1_000_000));

            if ($ready === false) {
                return false;
            }

            if ($ready === 0) {
                continue;
            }

            $chunk = @fread($stdout, 65536);

            if ($chunk === false || ($chunk === '' && feof($stdout))) {
                return false;
            }

            if ($chunk !== '') {
                $this->buffer .= $chunk;

                return true;
            }
        }

        return false;
    }
}
