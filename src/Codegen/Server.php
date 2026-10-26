<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Codegen;

use Closure;
use NativePhp\Simulator\Exceptions\SimulatorException;

/**
 * The page a recording is made from, served on this machine only. One request at a time,
 * since every one of them talks to the same device.
 */
final class Server
{
    /** How long a connection may take to send its request. */
    private const READ_SECONDS = 5;

    public function __construct(private readonly Session $session) {}

    /**
     * Serves until the process is stopped. $ready gets the page's address once it listens.
     *
     * @param  Closure(string): void  $ready
     */
    public function listen(int $port, Closure $ready): never
    {
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $code, $message);

        if ($socket === false) {
            throw new SimulatorException("Port {$port} is not free ({$message}). Pass another with --port.");
        }

        $ready("http://127.0.0.1:{$port}");

        while (true) {
            $client = @stream_socket_accept($socket, -1);

            if ($client === false) {
                continue;
            }

            $this->serve($client);
            fclose($client);
        }
    }

    /**
     * @return array{0: int, 1: string, 2: string} status, content type, and body
     */
    public function handle(string $method, string $path, string $body, ?string $host, ?string $origin = null): array
    {
        // Any page open in the browser can send a request here, and a rebound DNS name can
        // reach 127.0.0.1 too. Only this page, by this machine's own name, may drive the device.
        if (! self::local($host) || ($origin !== null && ! self::local((string) parse_url($origin, PHP_URL_HOST).':'.(parse_url($origin, PHP_URL_PORT) ?? '')))) {
            return [403, 'text/plain', 'Only the recording page on this machine can use this.'];
        }

        $input = json_decode($body, true);
        $input = is_array($input) ? $input : [];

        try {
            return match ($method.' '.$path) {
                'GET /' => [200, 'text/html; charset=utf-8', (string) file_get_contents(__DIR__.'/../../resources/codegen/index.html')],
                'GET /screen.png' => [200, 'image/png', $this->session->screenshot()],
                'GET /state' => self::json($this->session->state()),
                'POST /pick' => self::json($this->session->pick(
                    (float) ($input['x'] ?? 0),
                    (float) ($input['y'] ?? 0),
                    ($input['mode'] ?? 'tap') === 'assert',
                )),
                'POST /click' => self::json($this->session->click(
                    (float) ($input['x'] ?? 0),
                    (float) ($input['y'] ?? 0),
                    ($input['mode'] ?? 'tap') === 'assert',
                    is_string($input['text'] ?? null) ? $input['text'] : null,
                ) + ['code' => $this->session->script->chain()]),
                'POST /press' => self::json($this->session->press((string) ($input['button'] ?? '')) + ['code' => $this->session->script->chain()]),
                'POST /database' => self::json($this->session->checkRow(
                    (string) ($input['table'] ?? ''),
                    (int) ($input['rowid'] ?? 0),
                    ($input['assert'] ?? 'has') === 'count',
                    self::columns($input['columns'] ?? null),
                ) + ['code' => $this->session->script->chain()]),
                'POST /open' => self::json($this->session->open((string) ($input['path'] ?? '')) + ['code' => $this->session->script->chain()]),
                'POST /seed' => self::json(['code' => $this->session->seed(self::columns($input['tables'] ?? null) ?? [])]),
                'POST /undo' => self::json(['code' => $this->undo()]),
                default => [404, 'text/plain', 'Not found'],
            };
        } catch (SimulatorException $exception) {
            return self::json(['error' => $exception->getMessage()], 422);
        }
    }

    private function undo(): string
    {
        $this->session->script->undo();

        return $this->session->script->chain();
    }

    /**
     * @param  resource  $client
     */
    private function serve($client): void
    {
        // A browser opens spare connections it may never send on. Waiting on one for good
        // would stop every request after it.
        stream_set_timeout($client, self::READ_SECONDS);
        $head = '';

        while (! str_contains($head, "\r\n\r\n") && ($line = fgets($client)) !== false) {
            $head .= $line;
        }

        if (! preg_match('#^(\w+) (\S+)#', $head, $request)) {
            return;
        }

        $length = preg_match('/^content-length:\s*(\d+)/mi', $head, $match) ? (int) $match[1] : 0;
        $body = $length > 0 ? (string) stream_get_contents($client, $length) : '';
        $host = preg_match('/^host:\s*(\S+)/mi', $head, $match) ? $match[1] : null;
        $origin = preg_match('/^origin:\s*(\S+)/mi', $head, $match) ? $match[1] : null;
        [$status, $type, $content] = $this->handle($request[1], (string) parse_url($request[2], PHP_URL_PATH), $body, $host, $origin);

        fwrite($client, "HTTP/1.1 {$status} ".($status === 200 ? 'OK' : 'Error')."\r\n"
            ."Content-Type: {$type}\r\nContent-Length: ".strlen($content)."\r\n"
            ."Cache-Control: no-store\r\nConnection: close\r\n\r\n".$content);
    }

    /**
     * The names a request picked, columns or tables: a list of names, or null for none given.
     *
     * @return list<string>|null
     */
    private static function columns(mixed $columns): ?array
    {
        return is_array($columns) ? array_values(array_filter($columns, is_string(...))) : null;
    }

    /**
     * Whether a Host header, or an origin's host and port, names this machine.
     */
    private static function local(?string $host): bool
    {
        return $host !== null && preg_match('/^(127\.0\.0\.1|localhost|\[::1\])(:\d*)?$/', $host) === 1;
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private static function json(mixed $data, int $status = 200): array
    {
        return [$status, 'application/json', (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }
}
