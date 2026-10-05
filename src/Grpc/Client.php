<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Grpc;

use NativePhp\Simulator\Exceptions\SimulatorException;

class Client
{
    public function __construct(private readonly string $baseUrl) {}

    public function unary(string $method, string $message): string
    {
        return $this->call($method, Protobuf::frame($message));
    }

    /**
     * @param  list<string>  $messages
     */
    public function stream(string $method, array $messages): string
    {
        $body = '';

        foreach ($messages as $message) {
            $body .= Protobuf::frame($message);
        }

        return $this->call($method, $body);
    }

    /**
     * Like stream(), but writes each message onto the SAME underlying HTTP/2 request body
     * incrementally, with a real wall-clock gap between them — rather than assembling the
     * whole body up front and handing it to curl in one write.
     *
     * Exists for exactly one reason: a tap's down and up events sent as two SEPARATE
     * stream() calls (two independent HTTP/2 connections) did not reliably reach controls
     * inside a scrollable container, even with a real gap between the calls — plausibly
     * because the companion/OS never sees them as one continuous touch session when they
     * arrive as two unrelated requests. This keeps them on one connection, so whatever
     * continuity idb's touch injection relies on survives, while still giving iOS's gesture
     * arbitration the real time between down and up that an instantaneous tap never had.
     *
     * Implemented with a named pipe: curl reads its request body from the pipe (which it
     * streams rather than needing a known Content-Length for, same as any other `@file`
     * source), and this process writes into the pipe with a usleep() between writes — the
     * two ends rendezvous at the OS pipe-buffer level, so curl's single request genuinely
     * pauses mid-body for as long as the gap.
     *
     * @param  list<string>  $messages
     */
    public function streamPaced(string $method, array $messages, int $gapMicroseconds): string
    {
        $fifo = tempnam(sys_get_temp_dir(), 'grpcfifo');

        if ($fifo === false) {
            throw new SimulatorException('Could not call the companion.');
        }

        if (! unlink($fifo) || ! posix_mkfifo($fifo, 0600)) {
            throw new SimulatorException('Could not create a named pipe for the companion call.');
        }

        $header = tempnam(sys_get_temp_dir(), 'grpc');

        if ($header === false) {
            @unlink($fifo);

            throw new SimulatorException('Could not call the companion.');
        }

        $command = sprintf(
            'curl --silent --show-error --http2-prior-knowledge --dump-header %s -X POST %s -H %s -H %s --data-binary @%s',
            escapeshellarg($header),
            escapeshellarg($this->baseUrl.'/idb.CompanionService/'.$method),
            escapeshellarg('content-type: application/grpc'),
            escapeshellarg('te: trailers'),
            escapeshellarg($fifo),
        );

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            @unlink($fifo);
            @unlink($header);

            throw new SimulatorException('Could not call the companion.');
        }

        // Blocks until curl has opened the other end for reading — curl does this as soon
        // as it starts sending the request, which is immediately.
        $handle = fopen($fifo, 'w');

        if ($handle === false) {
            proc_close($process);
            @unlink($fifo);
            @unlink($header);

            throw new SimulatorException('Could not open the named pipe for the companion call.');
        }

        foreach ($messages as $index => $message) {
            fwrite($handle, Protobuf::frame($message));
            fflush($handle);

            if ($index < count($messages) - 1) {
                usleep($gapMicroseconds);
            }
        }

        fclose($handle);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        @unlink($fifo);

        if ($exit !== 0) {
            @unlink($header);

            throw new SimulatorException(trim($stderr === false ? '' : $stderr) ?: 'Could not call the companion.');
        }

        return $this->parseResponse($method, $stdout === false ? '' : $stdout, $header);
    }

    private function call(string $method, string $body): string
    {
        $header = tempnam(sys_get_temp_dir(), 'grpc');
        $payload = tempnam(sys_get_temp_dir(), 'grpc');

        if ($header === false || $payload === false) {
            throw new SimulatorException('Could not call the companion.');
        }

        file_put_contents($payload, $body);

        $command = sprintf(
            'curl --silent --show-error --http2-prior-knowledge --dump-header %s -X POST %s -H %s -H %s --data-binary @%s',
            escapeshellarg($header),
            escapeshellarg($this->baseUrl.'/idb.CompanionService/'.$method),
            escapeshellarg('content-type: application/grpc'),
            escapeshellarg('te: trailers'),
            escapeshellarg($payload),
        );

        $response = $this->execute($command);
        @unlink($payload);

        return $this->parseResponse($method, $response, $header);
    }

    private function parseResponse(string $method, string $response, string $headerFile): string
    {
        $headers = (string) file_get_contents($headerFile);
        @unlink($headerFile);

        if (preg_match('/grpc-status:\s*(\d+)/', $headers, $matches) === 1 && $matches[1] !== '0') {
            $message = 'unknown';

            if (preg_match('/grpc-message:\s*(.+)/', $headers, $messageMatch) === 1) {
                $message = trim($messageMatch[1]);
            }

            throw new SimulatorException("Companion [{$method}] failed: {$message}");
        }

        if (strlen($response) < 5) {
            return '';
        }

        $length = unpack('N', substr($response, 1, 4));

        return substr($response, 5, is_array($length) ? (int) $length[1] : 0);
    }

    private function execute(string $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new SimulatorException('Could not call the companion.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            throw new SimulatorException(trim((string) $stderr) ?: 'Could not call the companion.');
        }

        return $stdout === false ? '' : $stdout;
    }
}
