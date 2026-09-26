<?php

declare(strict_types=1);

namespace Tests\Support;

use NativePhp\Simulator\Driver;

final class FakeDriver implements Driver
{
    /** @var list<array{0: float, 1: float}> */
    public array $taps = [];

    /** @var list<string> */
    public array $texts = [];

    /** @var list<string> */
    public array $opened = [];

    /** @var list<string> */
    public array $databases = [];

    /** @var list<array{0: string, 1: string}> */
    public array $events = [];

    private int $reads = 0;

    /**
     * @param  list<list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>>  $trees
     */
    public function __construct(private readonly array $trees) {}

    public function ensureReady(): void {}

    public function open(string $url): void
    {
        $this->opened[] = $url;
        $this->events[] = ['open', $url];
    }

    public function describe(?string $treePath = null): array
    {
        $tree = $this->trees[min($this->reads, count($this->trees) - 1)];
        $this->reads++;

        return $tree;
    }

    public function tap(float $x, float $y): void
    {
        $this->taps[] = [$x, $y];
    }

    public function text(string $text): void
    {
        $this->texts[] = $text;
    }

    public function screenshot(string $path): void {}

    public function installDatabase(string $sqlitePath): void
    {
        $this->databases[] = $sqlitePath;
        $this->events[] = ['install', $sqlitePath];
    }
}
