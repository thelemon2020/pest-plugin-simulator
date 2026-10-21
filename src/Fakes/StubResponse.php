<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use NativePhp\Simulator\Exceptions\SimulatorException;
use Psr\Http\Message\ResponseInterface;

/**
 * A response a test gave Http::fake(), from Http::response(), as its parts (see Payload).
 * Http::failedConnection() is a closure, and travels as one.
 */
final class StubResponse
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public static function fromPromise(PromiseInterface $promise): self
    {
        $response = $promise->wait();

        if (! $response instanceof ResponseInterface) {
            throw new SimulatorException('A faked Http response has to be a response, as Http::response() makes.');
        }

        /** @var array<string, list<string>> $headers */
        $headers = $response->getHeaders();

        return new self($response->getStatusCode(), $headers, (string) $response->getBody());
    }

    public function toPromise(): PromiseInterface
    {
        return Factory::response($this->body, $this->status, $this->headers);
    }
}
