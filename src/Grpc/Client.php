<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Grpc;

use CurlHandle;
use NativePhp\Simulator\Exceptions\SimulatorException;

/**
 * Speaks to idb_companion over HTTP/2 through PHP's own curl extension, on one handle per
 * Client, so every call after the first rides the connection the first one opened instead
 * of starting a process and a TCP connection of its own.
 */
class Client
{
    /**
     * The companion listens on loopback, so a connect that has not finished in a few
     * seconds is not going to.
     */
    private const CONNECT_TIMEOUT_SECONDS = 5.0;

    /**
     * How long the companion has to answer one call, on top of whatever gaps
     * streamPaced() itself waits out between frames. An accessibility read on a wedged
     * companion has hung for 60s+, and with no bound at all that hang went straight
     * through Screen::until()'s own deadline. A healthy read takes well under a second
     * even on a busy host, and a swipe takes its own duration plus a little.
     */
    private const TIMEOUT_SECONDS = 30.0;

    private ?CurlHandle $handle = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly float $timeoutSeconds = self::TIMEOUT_SECONDS,
        private readonly float $connectTimeoutSeconds = self::CONNECT_TIMEOUT_SECONDS,
    ) {}

    public function unary(string $method, string $message): string
    {
        return $this->call($method, [Protobuf::frame($message)], 0);
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

        return $this->call($method, [$body], 0);
    }

    /**
     * Like stream(), but puts a real wall-clock gap between each message on the wire,
     * inside the SAME request body, rather than handing curl the whole body at once.
     *
     * The companion acts on each event of a `hid` stream as it reads it, so this is what
     * turns a tap's down and up into a touch actually held for the gap, and a typed
     * string into keys arriving at a pace the Simulator's keyboard keeps up with. Two
     * separate calls with a sleep between them are not the same thing: a tap's down and
     * up sent that way did not reliably reach controls inside a scrollable container,
     * plausibly because the companion never sees them as one continuous touch session.
     *
     * curl reads the body through the read callback below, one frame per call, and only
     * calls it again once it has sent the previous frame; the callback sleeps out the gap
     * before handing over the next one, so the request genuinely pauses mid-body.
     *
     * Before this used ext-curl, it ran the curl binary with `--data-binary @fifo` and
     * wrote into the named pipe with a sleep between writes. That never did what it
     * meant to: curl reads a `--data-binary @file` source to the end before it even
     * connects, so it waited out every gap, then sent all the frames back to back.
     *
     * @param  list<string>  $messages
     */
    public function streamPaced(string $method, array $messages, int $gapMicroseconds): string
    {
        $frames = [];

        foreach ($messages as $message) {
            $frames[] = Protobuf::frame($message);
        }

        return $this->call($method, $frames, $gapMicroseconds);
    }

    /**
     * @param  list<string>  $chunks  handed to curl in order, $gapMicroseconds apart
     */
    private function call(string $method, array $chunks, int $gapMicroseconds): string
    {
        $handle = $this->handle ??= $this->open();
        $headers = '';
        $next = 0;
        $pending = '';
        $pacing = max(0, count($chunks) - 1) * max(0, $gapMicroseconds) / 1_000_000;

        curl_setopt_array($handle, [
            CURLOPT_URL => $this->baseUrl.'/idb.CompanionService/'.$method,
            CURLOPT_TIMEOUT_MS => (int) ceil(($this->timeoutSeconds + $pacing) * 1000),
            // Trailers come through here too, which is where a normal gRPC response
            // carries its grpc-status.
            CURLOPT_HEADERFUNCTION => function (CurlHandle $handle, string $line) use (&$headers): int {
                $headers .= $line;

                return strlen($line);
            },
            CURLOPT_READFUNCTION => function (CurlHandle $handle, mixed $stream, int $length) use ($chunks, $gapMicroseconds, &$next, &$pending): string {
                if ($pending === '') {
                    if ($next >= count($chunks)) {
                        return '';
                    }

                    if ($next > 0 && $gapMicroseconds > 0) {
                        usleep($gapMicroseconds);
                    }

                    $pending = $chunks[$next++];
                }

                $piece = substr($pending, 0, $length);
                $pending = substr($pending, strlen($piece));

                return $piece;
            },
        ]);

        $response = curl_exec($handle);

        if (! is_string($response)) {
            $error = $this->failure($method, $handle);
            // A companion that timed out or dropped the connection mid-call is not one to
            // send the next call down; the next call opens a fresh connection.
            $this->handle = null;

            throw new SimulatorException($error);
        }

        $this->guardStatus($method, $headers, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));

        if (strlen($response) < 5) {
            return '';
        }

        $length = unpack('N', substr($response, 1, 4));

        return substr($response, 5, is_array($length) ? (int) $length[1] : 0);
    }

    private function open(): CurlHandle
    {
        $handle = curl_init();

        if ($handle === false) {
            throw new SimulatorException('Could not call the companion.');
        }

        $configured = curl_setopt_array($handle, [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['content-type: application/grpc', 'te: trailers', 'expect:'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($this->connectTimeoutSeconds * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TCP_NODELAY => true,
        ]);

        if (! $configured) {
            throw new SimulatorException("PHP's curl extension was built without HTTP/2, which idb_companion needs.");
        }

        return $handle;
    }

    private function failure(string $method, CurlHandle $handle): string
    {
        $error = curl_error($handle) ?: 'unknown error';

        return match (curl_errno($handle)) {
            CURLE_OPERATION_TIMEDOUT => "Companion [{$method}] did not answer in time: {$error}",
            CURLE_COULDNT_CONNECT => "Companion [{$method}] is not reachable at {$this->baseUrl}: {$error}",
            default => "Companion [{$method}] failed: {$error}",
        };
    }

    private function guardStatus(string $method, string $headers, int $httpStatus): void
    {
        if (preg_match('/^grpc-status:\s*(\d+)/mi', $headers, $status) === 1) {
            if ($status[1] === '0') {
                return;
            }

            $message = 'unknown';

            if (preg_match('/^grpc-message:[ \t]*(.*?)\s*$/mi', $headers, $messageMatch) === 1 && $messageMatch[1] !== '') {
                $message = rawurldecode($messageMatch[1]);
            }

            throw new SimulatorException("Companion [{$method}] failed: {$message}");
        }

        if ($httpStatus !== 200) {
            throw new SimulatorException("Companion [{$method}] failed: HTTP {$httpStatus}.");
        }
    }
}
