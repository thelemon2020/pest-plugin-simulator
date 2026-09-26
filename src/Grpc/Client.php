<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Grpc;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class Client
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
        $headers = (string) file_get_contents($header);
        @unlink($header);
        @unlink($payload);

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
