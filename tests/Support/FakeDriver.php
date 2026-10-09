<?php

declare(strict_types=1);

namespace Tests\Support;

use NativePhp\Simulator\Driver;
use NativePhp\Simulator\Exceptions\SimulatorException;

final class FakeDriver implements Driver
{
    /**
     * How many of the NEXT describe() calls should throw before answering normally —
     * simulates a companion that has not stabilized yet right after a screen just opened
     * (e.g. "window-server frontmost returned no application object").
     */
    public int $describeFailures = 0;
    /** @var list<array{0: float, 1: float}> */
    public array $taps = [];

    /** @var list<array{0: float, 1: float, 2: float}> */
    public array $presses = [];

    /** @var list<array{0: float, 1: float, 2: float, 3: float, 4: float}> */
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

    public ?string $recording = null;

    public int $descriptions = 0;

    /** @var array{0: float, 1: float} */
    public array $viewport = [390.0, 844.0];

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
        if ($this->describeFailures > 0) {
            $this->describeFailures--;

            throw new SimulatorException('window-server frontmost returned no application object');
        }

        $tree = $this->trees[min($this->reads, count($this->trees) - 1)];
        $this->reads++;
        $this->descriptions++;

        if ($treePath !== null) {
            file_put_contents($treePath, json_encode($tree) ?: '[]');
        }

        return $tree;
    }

    public function tap(float $x, float $y): void
    {
        $this->taps[] = [$x, $y];
    }

    public function press(float $x, float $y, float $seconds = 0.8): void
    {
        $this->presses[] = [$x, $y, $seconds];
    }

    public function swipe(float $x1, float $y1, float $x2, float $y2, float $seconds = 0.3): void
    {
        $this->swipes[] = [$x1, $y1, $x2, $y2, $seconds];
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
        return $this->viewport;
    }

    public function screenshot(string $path): void
    {
        file_put_contents($path, 'png');
    }

    public function startRecording(string $path): void
    {
        $this->recording = $path;
        $this->events[] = ['record', $path];
    }

    public function stopRecording(): void
    {
        $this->events[] = ['record-stop'];
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
