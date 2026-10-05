<?php

declare(strict_types=1);

namespace Tests\Support;

use NativePhp\Simulator\Grpc\Client;

final class RecordingClient extends Client
{
    /** @var list<array{0: string, 1: string, 2: list<string>, 3?: int}> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct('http://127.0.0.1:0');
    }

    public function stream(string $method, array $messages): string
    {
        $this->calls[] = ['stream', $method, $messages];

        return '';
    }

    public function streamPaced(string $method, array $messages, int $gapMicroseconds): string
    {
        $this->calls[] = ['streamPaced', $method, $messages, $gapMicroseconds];

        return '';
    }
}
