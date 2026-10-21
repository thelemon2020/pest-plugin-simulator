<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use NativePhp\Simulator\Driver;
use NativePhp\Simulator\Exceptions\SimulatorException;

/**
 * @phpstan-import-type Element from Driver
 * @phpstan-import-type OpenPrompt from Driver
 */
final class FakeDriver implements Driver
{
    /**
     * How many of the NEXT describe() calls should throw before answering normally —
     * simulates a companion that has not stabilized yet right after a screen just opened
     * (e.g. "window-server frontmost returned no application object").
     */
    public int $describeFailures = 0;

    /**
     * What those failures throw, when not that complaint.
     *
     * @var (Closure(): SimulatorException)|null
     */
    public ?Closure $describeError = null;

    /** What open() throws, if anything. */
    public ?SimulatorException $openError = null;

    /** What resendsLateAnswers() says: iOS's answer by default. */
    public bool $resends = true;

    /**
     * Builds each read instead of the trees given, for a screen that answers what the test
     * sends it.
     *
     * @var (Closure(): list<Element>)|null
     */
    public ?Closure $reading = null;

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

    /**
     * The app's own storage: each file's bytes, by its path under Library/Application
     * Support/ or app_storage/persisted_data/ (see Driver::pushAppFile()).
     *
     * @var array<string, string>
     */
    public array $appFiles = [];

    /**
     * Runs before each pullAppFile(), with the path, for an app that writes while it runs.
     *
     * @var (Closure(string): void)|null
     */
    public ?Closure $pulling = null;

    /** Whether tailAppFile() throws, as a read over adb that failed. */
    public bool $tailFails = false;

    /**
     * The pattern of each tailAppFile(), kept apart from $events: every step reads the
     * app's log.
     *
     * @var list<string>
     */
    public array $tails = [];

    /** @var list<array{0: string, 1?: string}> */
    public array $events = [];

    /** @var list<list<string>> */
    public array $grants = [];

    /** @var list<list<string>> */
    public array $revokes = [];

    /** @var array<string, string> */
    public array $savedLogs = [];

    public ?string $recording = null;

    /**
     * Device settings in the order they were sent. A null value puts the device's own back.
     *
     * @var list<array{0: string, 1: mixed}>
     */
    public array $settings = [];

    /** A setting that throws when it is put back. */
    public ?string $failsRestoring = null;

    /** @var list<float> */
    public array $backgrounds = [];

    public int $relaunches = 0;

    public int $descriptions = 0;

    /**
     * What openPrompts() answers.
     *
     * @var list<OpenPrompt>
     */
    public array $openPrompts = [];

    /**
     * What the next freshlyInstalled() answers, as if the app had just been built.
     */
    public bool $fresh = false;

    public int $promptReads = 0;

    /** @var array{0: float, 1: float} */
    public array $viewport = [390.0, 844.0];

    public float $homeIndicator = 0.0;

    /** @var list<array<string, mixed>> */
    public array $pushes = [];

    /**
     * An image file screenshot() copies, instead of writing a placeholder.
     */
    public ?string $screen = null;

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
        if ($this->openError !== null) {
            throw $this->openError;
        }

        $this->opened[] = $url;
        $this->events[] = ['open', $url];
    }

    public function closeSystemUi(bool $stopping): void
    {
        $this->events[] = ['close-system-ui', $stopping ? 'stopping' : 'running'];
    }

    public function describe(?string $treePath = null): array
    {
        if ($this->describeFailures > 0) {
            $this->describeFailures--;

            throw $this->describeError !== null
                ? ($this->describeError)()
                : new SimulatorException('window-server frontmost returned no application object');
        }

        $tree = $this->reading !== null ? ($this->reading)() : $this->trees[min($this->reads, count($this->trees) - 1)];
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

    public function enter(): void
    {
        $this->events[] = ['enter'];
    }

    public function text(string $text): void
    {
        $this->texts[] = $text;
    }

    public function resendsLateAnswers(): bool
    {
        return $this->resends;
    }

    public function viewport(): array
    {
        return $this->viewport;
    }

    public function homeIndicator(): float
    {
        return $this->homeIndicator;
    }

    public function screenshot(string $path): void
    {
        if ($this->screen !== null) {
            copy($this->screen, $path);

            return;
        }

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
        $this->grants[] = $services;
    }

    public function revoke(array $services): void
    {
        $this->revokes[] = $services;
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
        $this->appFiles['database/database.sqlite'] = $this->databaseContents;
        unset($this->appFiles['database/database.sqlite-wal'], $this->appFiles['database/database.sqlite-shm']);
    }

    public function pushAppFile(string $local, string $relative): void
    {
        $contents = file_get_contents($local);

        if (! is_string($contents)) {
            throw new SimulatorException("Could not read [{$local}].");
        }

        $this->appFiles[$relative] = $contents;
        $this->events[] = ['pushFile', $relative];
    }

    public function pullAppFile(string $relative, string $local): bool
    {
        $this->events[] = ['pullFile', $relative];

        if ($this->pulling !== null) {
            ($this->pulling)($relative);
        }

        if (! array_key_exists($relative, $this->appFiles)) {
            return false;
        }

        return file_put_contents($local, $this->appFiles[$relative]) !== false;
    }

    public function tailAppFile(string $pattern, ?int $offset, ?string $from = null): ?array
    {
        $this->tails[] = $pattern;

        if ($this->pulling !== null) {
            ($this->pulling)($pattern);
        }

        $matches = array_values(array_filter(array_keys($this->appFiles), fn (string $path): bool => fnmatch($pattern, $path)));

        if ($matches === []) {
            return null;
        }

        // The newest is the last one written, as the app writes them.
        $path = end($matches);
        $contents = $this->appFiles[$path];
        $size = strlen($contents);

        if ($this->tailFails) {
            throw new SimulatorException('adb: device offline');
        }

        if ($offset === null) {
            return ['path' => $path, 'size' => $size, 'bytes' => ''];
        }

        return ['path' => $path, 'size' => $size, 'bytes' => substr($contents, $path === $from && $offset <= $size ? $offset : 0)];
    }

    /**
     * Removing a file that is not there leaves no event, so a test that never wrote one
     * sees the steps it expects.
     */
    public function removeAppFile(string $relative): void
    {
        if (! array_key_exists($relative, $this->appFiles)) {
            return;
        }

        unset($this->appFiles[$relative]);
        $this->events[] = ['removeFile', $relative];
    }

    public function appearance(?string $mode): void
    {
        $this->setting('appearance', $mode);
    }

    public function textSize(?string $size): void
    {
        $this->setting('textSize', $size);
    }

    public function location(?array $coordinates): void
    {
        $this->setting('location', $coordinates);
    }

    public function statusBar(bool $fixed): void
    {
        $this->setting('statusBar', $fixed);
    }

    public function background(float $seconds): void
    {
        $this->backgrounds[] = $seconds;
        $this->events[] = ['background'];
    }

    public function relaunch(): void
    {
        $this->relaunches++;
        $this->events[] = ['relaunch'];
    }

    public function addMedia(string $path): void
    {
        $this->events[] = ['addMedia', $path];
    }

    public function biometric(string $action): void
    {
        $this->events[] = ['biometric', $action];
    }

    public function push(array $payload): void
    {
        $this->pushes[] = $payload;
        $this->events[] = ['push'];
    }

    public function openPrompts(): array
    {
        $this->promptReads++;

        return $this->openPrompts;
    }

    public function freshlyInstalled(): bool
    {
        $fresh = $this->fresh;
        $this->fresh = false;

        return $fresh;
    }

    private function setting(string $name, mixed $value): void
    {
        if (($value === null || $value === false) && $name === $this->failsRestoring) {
            throw new SimulatorException('device offline');
        }

        $this->settings[] = [$name, $value];
        $this->events[] = ['setting', $name];
    }
}
