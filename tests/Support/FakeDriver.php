<?php

declare(strict_types=1);

namespace Tests\Support;

use NativePhp\Simulator\Driver;

final class FakeDriver implements Driver
{
    /** @var list<array{0: float, 1: float}> */
    public array $taps = [];

    /** @var list<array{0: float, 1: float, 2: float, 3: float}> */
    public array $swipes = [];

    public int $backs = 0;

    public int $clears = 0;

    public int $cleared = 0;

    /** @var list<string> */
    public array $texts = [];

    /** @var list<string> */
    public array $opened = [];

    /** @var list<string> */
    public array $databases = [];

    public string $databaseContents = '';

    /** @var list<array{0: string, 1: string}> */
    public array $events = [];

    /** @var list<list<string>> */
    public array $grants = [];

    /** @var list<list<string>> */
    public array $revokes = [];

    /** @var array<string, string> */
    public array $savedLogs = [];

    private int $reads = 0;

    /**
     * @param  list<list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>>  $trees
     */
    public function __construct(private readonly array $trees) {}

    public function ensureReady(): void
    {
        $this->events[] = ['ready'];
    }

    public function open(string $url): void
    {
        $this->opened[] = $url;
        $this->events[] = ['open', $url];
    }

    public function describe(?string $treePath = null): array
    {
        $tree = $this->trees[min($this->reads, count($this->trees) - 1)];
        $this->reads++;

        if ($treePath !== null) {
            file_put_contents($treePath, json_encode($tree) ?: '[]');
        }

        return $tree;
    }

    public function tap(float $x, float $y): void
    {
        $this->taps[] = [$x, $y];
    }

    public function swipe(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->swipes[] = [$x1, $y1, $x2, $y2];
    }

    public function back(): void
    {
        $this->backs++;
    }

    public function clear(int $characters = 40): void
    {
        $this->clears++;
        $this->cleared = $characters;
    }

    public function text(string $text): void
    {
        $this->texts[] = $text;
    }

    public function viewport(): array
    {
        return [390.0, 844.0];
    }

    public function screenshot(string $path): void
    {
        file_put_contents($path, 'png');
    }

    public function grant(array $services): void
    {
        $this->grants[] = array_values($services);
    }

    public function revoke(array $services): void
    {
        $this->revokes[] = array_values($services);
    }

    public function captureLogs(string $directory): array
    {
        $paths = [];

        foreach ($this->savedLogs as $name => $contents) {
            $path = $directory.'/'.$name;
            file_put_contents($path, $contents);
            $paths[] = $path;
        }

        return $paths;
    }

    public function installDatabase(string $sqlitePath): void
    {
        $contents = file_get_contents($sqlitePath);
        $this->databaseContents = is_string($contents) ? $contents : '';
        $this->databases[] = $sqlitePath;
        $this->events[] = ['install', $sqlitePath];
    }
}
