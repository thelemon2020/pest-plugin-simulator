<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\StreamInterface;
use Stringable;

/**
 * The requests the app's faked Http client recorded, as plain arrays for the record file,
 * and as Laravel's own Request and Response again for the test's asserts. A request's body
 * is a stream, which does not serialize, so it travels as a string.
 *
 * Laravel reads a GET request's query and a multipart request's parts from the data the
 * request was sent with, not from its body, so that travels too, as do its attributes.
 *
 * @phpstan-type Pair array{request: array{method: string, url: string, headers: array<string, list<string>>, body: string, data?: array<array-key, mixed>, attributes?: array<array-key, mixed>}, response: array{status: int, headers: array<string, list<string>>, body: string}|null}
 */
final class HttpRecords
{
    /**
     * @return list<Pair>
     */
    public static function encode(Factory $factory): array
    {
        $pairs = [];

        foreach ($factory->recorded() as [$request, $response]) {
            $psr = $request->toPsrRequest();
            /** @var array<string, list<string>> $headers */
            $headers = $psr->getHeaders();
            $pair = [
                'request' => [
                    'method' => $psr->getMethod(),
                    'url' => (string) $psr->getUri(),
                    'headers' => $headers,
                    'body' => (string) $psr->getBody(),
                    'data' => self::plain($request->data()),
                    'attributes' => self::plain($request->attributes()),
                ],
                'response' => null,
            ];

            if ($response instanceof Response) {
                $answer = $response->toPsrResponse();
                /** @var array<string, list<string>> $answerHeaders */
                $answerHeaders = $answer->getHeaders();
                $pair['response'] = ['status' => $answer->getStatusCode(), 'headers' => $answerHeaders, 'body' => (string) $answer->getBody()];
            }

            $pairs[] = $pair;
        }

        return $pairs;
    }

    /**
     * @param  list<Pair>  $pairs
     * @return list<array{0: Request, 1: Response|null}>
     */
    public static function decode(array $pairs): array
    {
        return array_map(function (array $pair): array {
            $request = (new Request(new PsrRequest($pair['request']['method'], $pair['request']['url'], $pair['request']['headers'], $pair['request']['body'])))
                ->withData($pair['request']['data'] ?? []);
            $request->setRequestAttributes($pair['request']['attributes'] ?? []);

            return [
                $request,
                $pair['response'] === null ? null : new Response(new PsrResponse($pair['response']['status'], $pair['response']['headers'], $pair['response']['body'])),
            ];
        }, $pairs);
    }

    /**
     * Data as arrays and scalars only: a multipart file's contents, a stream, becomes its
     * bytes, and another object its string or its class.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function plain(array $data): array
    {
        return array_map(function (mixed $value): mixed {
            return match (true) {
                is_array($value) => self::plain($value),
                is_resource($value) => (string) stream_get_contents($value, -1, 0),
                $value instanceof StreamInterface => (string) $value,
                $value instanceof Stringable => (string) $value,
                is_object($value) => $value::class,
                default => $value,
            };
        }, $data);
    }
}
