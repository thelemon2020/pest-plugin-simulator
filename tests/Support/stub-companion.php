<?php

declare(strict_types=1);

/*
 * A stand-in for idb_companion's gRPC endpoint: just enough cleartext HTTP/2 (prior
 * knowledge) to answer the Client, and to write down when each request frame arrived.
 *
 *   php stub-companion.php <responses.json> <requests.jsonl>
 *
 * Prints the port it listens on, then serves until it is killed. Each request takes the
 * next scripted response, and the last one repeats. A response is any of:
 *
 *   {"messages": ["<base64>", ...], "status": 0, "message": "..."}  answered normally
 *   {"trailersOnly": true, "status": 2, "message": "..."}            grpc-status in the headers
 *   {"hang": true}                                                   never answered
 *
 * Request headers are not decoded. curl Huffman-codes them, and nothing here needs them.
 */

const FRAME_DATA = 0x0;
const FRAME_HEADERS = 0x1;
const FRAME_SETTINGS = 0x4;
const FRAME_PING = 0x6;
const FRAME_GOAWAY = 0x7;
const FRAME_WINDOW_UPDATE = 0x8;
const FLAG_END_STREAM = 0x1;
const FLAG_ACK = 0x1;
const FLAG_END_HEADERS = 0x4;
const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

$responses = json_decode((string) file_get_contents($argv[1]), true);
$log = $argv[2];
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

if ($server === false) {
    fwrite(STDERR, $error);
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, strrpos($name, ':') + 1)."\n");
fflush(STDOUT);

/** @var array<int, array{socket: resource, number: int, buffer: string, preface: bool, streams: array<int, list<array{at: float, bytes: string}>>}> $connections */
$connections = [];
$served = 0;
$accepted = 0;

while (true) {
    $read = [$server];

    foreach ($connections as $connection) {
        $read[] = $connection['socket'];
    }

    $write = $except = null;

    if (stream_select($read, $write, $except, 1) === false) {
        exit(1);
    }

    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = stream_socket_accept($server, 0);

            if ($client !== false) {
                $accepted++;
                $connections[get_resource_id($client)] = ['socket' => $client, 'number' => $accepted, 'buffer' => '', 'preface' => false, 'streams' => []];
                fwrite($client, frame(FRAME_SETTINGS, 0, 0, ''));
            }

            continue;
        }

        $id = get_resource_id($socket);
        $bytes = fread($socket, 65536);

        if ($bytes === '' || $bytes === false) {
            fclose($socket);
            unset($connections[$id]);

            continue;
        }

        $at = microtime(true);
        $connections[$id]['buffer'] .= $bytes;

        if (! $connections[$id]['preface']) {
            if (strlen($connections[$id]['buffer']) < strlen(PREFACE)) {
                continue;
            }

            $connections[$id]['buffer'] = substr($connections[$id]['buffer'], strlen(PREFACE));
            $connections[$id]['preface'] = true;
        }

        while (strlen($connections[$id]['buffer']) >= 9) {
            $header = unpack('Nlength/Ctype/Cflags/Nstream', "\0".substr($connections[$id]['buffer'], 0, 9));
            $length = $header['length'];

            if (strlen($connections[$id]['buffer']) < 9 + $length) {
                break;
            }

            $payload = substr($connections[$id]['buffer'], 9, $length);
            $connections[$id]['buffer'] = substr($connections[$id]['buffer'], 9 + $length);
            $stream = $header['stream'] & 0x7FFFFFFF;

            switch ($header['type']) {
                case FRAME_SETTINGS:
                    if (($header['flags'] & FLAG_ACK) === 0) {
                        fwrite($socket, frame(FRAME_SETTINGS, FLAG_ACK, 0, ''));
                    }

                    break;
                case FRAME_PING:
                    if (($header['flags'] & FLAG_ACK) === 0) {
                        fwrite($socket, frame(FRAME_PING, FLAG_ACK, 0, $payload));
                    }

                    break;
                case FRAME_HEADERS:
                    $connections[$id]['streams'][$stream] ??= [];

                    break;
                case FRAME_DATA:
                    if ($length > 0) {
                        $connections[$id]['streams'][$stream][] = ['at' => $at, 'bytes' => base64_encode($payload)];
                        fwrite($socket, frame(FRAME_WINDOW_UPDATE, 0, 0, pack('N', $length)));
                        fwrite($socket, frame(FRAME_WINDOW_UPDATE, 0, $stream, pack('N', $length)));
                    }

                    break;
                case FRAME_GOAWAY:
                    break;
            }

            if (in_array($header['type'], [FRAME_HEADERS, FRAME_DATA], true) && ($header['flags'] & FLAG_END_STREAM) !== 0) {
                $response = $responses[min($served, count($responses) - 1)];
                $served++;
                file_put_contents($log, json_encode([
                    'connection' => $connections[$id]['number'],
                    'stream' => $stream,
                    'data' => $connections[$id]['streams'][$stream] ?? [],
                ])."\n", FILE_APPEND);
                unset($connections[$id]['streams'][$stream]);
                respond($socket, $stream, $response);
            }
        }
    }
}

function frame(int $type, int $flags, int $stream, string $payload): string
{
    return substr(pack('N', strlen($payload)), 1).chr($type).chr($flags).pack('N', $stream).$payload;
}

/**
 * HPACK, literal without indexing, new name, no Huffman.
 *
 * @param  array<string, string>  $headers
 */
function headerBlock(array $headers): string
{
    $block = '';

    foreach ($headers as $name => $value) {
        $block .= "\x00".hpackLength(strlen($name)).$name.hpackLength(strlen($value)).$value;
    }

    return $block;
}

function hpackLength(int $length): string
{
    if ($length < 127) {
        return chr($length);
    }

    $bytes = chr(127);
    $length -= 127;

    while ($length >= 128) {
        $bytes .= chr(($length & 0x7F) | 0x80);
        $length >>= 7;
    }

    return $bytes.chr($length);
}

/**
 * @param  resource  $socket
 * @param  array{messages?: list<string>, status?: int, message?: string, trailersOnly?: bool, hang?: bool}  $response
 */
function respond($socket, int $stream, array $response): void
{
    if ($response['hang'] ?? false) {
        return;
    }

    $status = ['grpc-status' => (string) ($response['status'] ?? 0)];

    if (isset($response['message'])) {
        $status['grpc-message'] = $response['message'];
    }

    if ($response['trailersOnly'] ?? false) {
        fwrite($socket, frame(FRAME_HEADERS, FLAG_END_HEADERS | FLAG_END_STREAM, $stream, headerBlock([
            ':status' => '200',
            'content-type' => 'application/grpc',
            ...$status,
        ])));

        return;
    }

    $body = '';

    foreach ($response['messages'] ?? [] as $message) {
        $decoded = base64_decode($message);
        $body .= chr(0).pack('N', strlen($decoded)).$decoded;
    }

    fwrite($socket, frame(FRAME_HEADERS, FLAG_END_HEADERS, $stream, headerBlock([
        ':status' => '200',
        'content-type' => 'application/grpc',
    ])));

    if ($body !== '') {
        fwrite($socket, frame(FRAME_DATA, 0, $stream, $body));
    }

    fwrite($socket, frame(FRAME_HEADERS, FLAG_END_HEADERS | FLAG_END_STREAM, $stream, headerBlock($status)));
}
