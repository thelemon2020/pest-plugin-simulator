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
     * streamPaced() itself waits out between frames and however long a gesture takes. An
     * accessibility read on a wedged companion has hung for 60s+, and with no bound at all
     * that hang went straight through Screen::until()'s own deadline. A healthy read takes
     * well under a second even on a busy host.
     */
    public const TIMEOUT_SECONDS = 30.0;

    /**
     * A connection kept from an earlier call can be closed by the companion just as it is
     * reused. curl then replays the request on a fresh connection by itself, but it cannot
     * rewind a body it read through a callback (PHP has no CURLOPT_SEEKFUNCTION), so it
     * gives up with CURLE_SEND_FAIL_REWIND, which PHP does not define. curl only rewinds a
     * request it has judged safe to send again, so this one always gets a second go.
     */
    private const SEND_FAIL_REWIND = 65;

    /**
     * curl's codes for a connection that died under a request before any answer came
     * back, when curl reports that instead of trying to replay it. Only a reused
     * connection gets a second go on these; a fresh one dying means the companion is not
     * answering. PHP does not define 16 and 92.
     */
    private const DEAD_CONNECTION = [
        16, // CURLE_HTTP2
        CURLE_GOT_NOTHING,
        CURLE_SEND_ERROR,
        CURLE_RECV_ERROR,
        92, // CURLE_HTTP2_STREAM
    ];

    private ?CurlHandle $handle = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly float $timeoutSeconds = self::TIMEOUT_SECONDS,
        private readonly float $connectTimeoutSeconds = self::CONNECT_TIMEOUT_SECONDS,
    ) {}

    public function unary(string $method, string $message): string
    {
        return $this->call($method, [Protobuf::frame($message)], 0, 0.0);
    }

    /**
     * @param  list<string>  $messages
     * @param  float  $durationSeconds  how long the companion itself spends acting on the
     *                                  messages before it answers, such as a swipe's own
     *                                  duration; the timeout allows for it on top
     */
    public function stream(string $method, array $messages, float $durationSeconds = 0.0): string
    {
        return $this->call($method, [implode('', array_map(Protobuf::frame(...), $messages))], 0, $durationSeconds);
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
        return $this->call($method, array_map(Protobuf::frame(...), $messages), $gapMicroseconds, 0.0);
    }

    /**
     * @param  list<string>  $chunks  handed to curl in order, $gapMicroseconds apart
     */
    private function call(string $method, array $chunks, int $gapMicroseconds, float $durationSeconds): string
    {
        return $this->exchange($method, $chunks, $gapMicroseconds, $durationSeconds, retry: true)
            ?? $this->exchange($method, $chunks, $gapMicroseconds, $durationSeconds, retry: false)
            ?? throw new SimulatorException("Companion [{$method}] failed.");
    }

    /**
     * @param  list<string>  $chunks
     * @return string|null null when a reused connection died before any answer and
     *                     $retry allows one more go on a fresh connection
     */
    private function exchange(string $method, array $chunks, int $gapMicroseconds, float $durationSeconds, bool $retry): ?string
    {
        $handle = $this->handle ??= $this->open();
        $headers = '';
        $next = 0;
        $pending = '';
        $mask = null;
        $pacing = max(0, count($chunks) - 1) * max(0, $gapMicroseconds) / 1_000_000;

        curl_setopt_array($handle, [
            CURLOPT_URL => $this->baseUrl.'/idb.CompanionService/'.$method,
            CURLOPT_TIMEOUT_MS => (int) ceil(($this->timeoutSeconds + $pacing + max(0.0, $durationSeconds)) * 1000),
            // Trailers come through here too, which is where a normal gRPC response
            // carries its grpc-status.
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$headers): int {
                $headers .= $line;

                return strlen($line);
            },
            CURLOPT_READFUNCTION => static function (CurlHandle $handle, mixed $stream, int $length) use ($chunks, $gapMicroseconds, &$next, &$pending, &$mask): string {
                if ($pending === '') {
                    if ($next >= count($chunks)) {
                        // curl asks again only once it has sent the last frame, so a
                        // held signal can be let through now.
                        self::releaseSignals($mask);

                        return '';
                    }

                    if ($next > 0 && $gapMicroseconds > 0) {
                        $mask ??= self::holdSignals();
                        self::pause($gapMicroseconds);
                    }

                    $pending = $chunks[$next++];
                }

                $piece = substr($pending, 0, $length);
                $pending = substr($pending, strlen($piece));

                return $piece;
            },
        ]);

        try {
            $response = curl_exec($handle);
        } finally {
            self::releaseSignals($mask);
        }

        if (! is_string($response)) {
            $errno = curl_errno($handle);
            $reused = curl_getinfo($handle, CURLINFO_NUM_CONNECTS) === 0;
            $replayable = $headers === ''
                && ($errno === self::SEND_FAIL_REWIND || ($reused && in_array($errno, self::DEAD_CONNECTION, true)));
            $error = $this->failure($method, $handle);
            // A companion that timed out or dropped the connection mid-call is not one to
            // send the next call down; the next call opens a fresh connection.
            $this->handle = null;

            if ($retry && $replayable) {
                return null;
            }

            throw new SimulatorException($error);
        }

        $this->guardStatus($method, $headers, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));

        if (strlen($response) < 5) {
            return '';
        }

        $length = unpack('N', substr($response, 1, 4));

        return substr($response, 5, is_array($length) ? (int) $length[1] : 0);
    }

    /**
     * Sleeps the whole gap. usleep() returns early when a signal with a handler lands,
     * which would quietly shorten a tap's hold to almost nothing.
     */
    private static function pause(int $microseconds): void
    {
        $until = hrtime(true) + $microseconds * 1000;

        while (($left = $until - hrtime(true)) > 0) {
            usleep(max(1, intdiv($left, 1000)));
        }
    }

    /**
     * Holds Ctrl+C and SIGTERM back while frames are still going out apart. Shutdown's
     * handler stops the process from inside this callback, after a tap's down and before
     * its up, which would leave a finger on the Simulator's glass. The signal still
     * arrives, as soon as the last frame is sent.
     *
     * @return array<int>|null the mask to put back, or null when signals cannot be held
     */
    private static function holdSignals(): ?array
    {
        if (! function_exists('pcntl_sigprocmask')) {
            return null;
        }

        $previous = [];

        return pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM], $previous) ? $previous : null;
    }

    /**
     * @param  array<int>|null  $mask
     */
    private static function releaseSignals(?array &$mask): void
    {
        if ($mask === null) {
            return;
        }

        pcntl_sigprocmask(SIG_SETMASK, $mask);
        $mask = null;
    }

    private function open(): CurlHandle
    {
        $handle = curl_init();

        if ($handle === false) {
            throw new SimulatorException('Could not call the companion.');
        }

        if (! curl_setopt($handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE)) {
            throw new SimulatorException("PHP's curl extension was built without HTTP/2, which idb_companion needs.");
        }

        $configured = curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['content-type: application/grpc', 'te: trailers', 'expect:'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($this->connectTimeoutSeconds * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TCP_NODELAY => true,
        ]);

        if (! $configured) {
            throw new SimulatorException('Could not set up a curl handle for the companion: '.(curl_error($handle) ?: 'an option was rejected'));
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
