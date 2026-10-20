<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;

/**
 * The requests the app's faked Http client recorded, as plain arrays for the record file,
 * and as Laravel's own Request and Response again for the test's asserts. A request's body
 * is a stream, which does not serialize, so it travels as a string.
 *
 * @phpstan-type Pair array{request: array{method: string, url: string, headers: array<string, list<string>>, body: string}, response: array{status: int, headers: array<string, list<string>>, body: string}|null}
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
                'request' => ['method' => $psr->getMethod(), 'url' => (string) $psr->getUri(), 'headers' => $headers, 'body' => (string) $psr->getBody()],
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
        return array_map(fn (array $pair): array => [
            new Request(new PsrRequest($pair['request']['method'], $pair['request']['url'], $pair['request']['headers'], $pair['request']['body'])),
            $pair['response'] === null ? null : new Response(new PsrResponse($pair['response']['status'], $pair['response']['headers'], $pair['response']['body'])),
        ], $pairs);
    }
}
