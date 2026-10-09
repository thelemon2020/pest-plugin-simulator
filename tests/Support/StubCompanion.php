<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Runs stub-companion.php in its own process, so a real Client can call it over a real
 * HTTP/2 connection.
 */
final class StubCompanion
{
    /** @var resource */
    private $process;

    /**
     * @param  resource  $process
     */
    private function __construct(
        $process,
        private readonly int $port,
        private readonly string $script,
        private readonly string $log,
    ) {
        $this->process = $process;
    }

    /**
     * @param  list<array{messages?: list<string>, status?: int, message?: string, trailersOnly?: bool, hang?: bool}>  $responses
     */
    public static function start(array $responses): self
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'stub-companion');
        $log = (string) tempnam(sys_get_temp_dir(), 'stub-companion');
        $encoded = array_map(function (array $response): array {
            if (isset($response['messages'])) {
                $response['messages'] = array_map(base64_encode(...), $response['messages']);
            }

            return $response;
        }, $responses);
        file_put_contents($script, json_encode($encoded));

        $process = proc_open(
            [PHP_BINARY, __DIR__.'/stub-companion.php', $script, $log],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the stub companion.');
        }

        $port = trim((string) fgets($pipes[1]));

        if (! ctype_digit($port)) {
            proc_terminate($process);

            throw new RuntimeException('The stub companion did not start: '.stream_get_contents($pipes[2]));
        }

        return new self($process, (int) $port, $script, $log);
    }

    public static function unusedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new RuntimeException('Could not find a free port.');
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    public function url(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    /**
     * Every request answered so far, in order.
     *
     * @return list<array{connection: int, frames: list<array{at: float, bytes: string}>, body: string}>
     */
    public function requests(): array
    {
        $requests = [];

        foreach (file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $request = json_decode($line, true);
            $frames = array_map(fn (array $frame): array => [
                'at' => (float) $frame['at'],
                'bytes' => (string) base64_decode($frame['bytes']),
            ], $request['data']);

            $requests[] = [
                'connection' => (int) $request['connection'],
                'frames' => $frames,
                'body' => implode('', array_column($frames, 'bytes')),
            ];
        }

        return $requests;
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->script);
        @unlink($this->log);
    }
}
