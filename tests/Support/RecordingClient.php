<?php

declare(strict_types=1);

namespace Tests\Support;

use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Grpc\Client;
use NativePhp\Simulator\Grpc\Protobuf;

final class RecordingClient extends Client
{
    /** @var list<array{0: string, 1: string, 2: list<string>|list<list<string>>, 3?: int|float}> */
    public array $calls = [];

    /**
     * Accessibility trees (JSON) that `accessibility_info` answers with, one per read.
     * The last one repeats.
     *
     * @var list<string>
     */
    public array $trees = [];

    private int $reads = 0;

    public function __construct()
    {
        parent::__construct('http://127.0.0.1:0');
    }

    public function unary(string $method, string $message): string
    {
        $this->calls[] = ['unary', $method, [$message]];

        if ($this->trees === []) {
            throw new SimulatorException("Companion [{$method}] failed: nothing scripted");
        }

        $tree = $this->trees[min($this->reads, count($this->trees) - 1)];
        $this->reads++;

        return Protobuf::messageField(1, $tree);
    }

    public function stream(string $method, array $messages, float $durationSeconds = 0.0): string
    {
        $this->calls[] = $durationSeconds > 0 ? ['stream', $method, $messages, $durationSeconds] : ['stream', $method, $messages];

        return '';
    }

    public function streamPaced(string $method, array $messages, int $gapMicroseconds): string
    {
        $this->calls[] = ['streamPaced', $method, $messages, $gapMicroseconds];

        return '';
    }

    public function streamStrokes(string $method, array $strokes, int $gapMicroseconds): string
    {
        $this->calls[] = ['streamStrokes', $method, $strokes, $gapMicroseconds];

        return '';
    }
}
