<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Codegen;

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\DeviceDatabase;
use NativePhp\Simulator\Driver;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Fakes\FakeSync;
use NativePhp\Simulator\IdleMarker;
use NativePhp\Simulator\Screen;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\SystemUi;
use Throwable;

/**
 * A test being recorded: the app open on one device, and each click turned into a step,
 * run on the screen, then kept.
 *
 * It opens the app as it is. A test's first screen() also copies in the test database and
 * the fakes; a recording runs against what the app already has.
 *
 * @phpstan-import-type Element from Driver
 * @phpstan-import-type Snapshot from Changes
 * @phpstan-import-type Change from Changes
 */
final class Session
{
    public readonly Script $script;

    /**
     * What the last state() read, for pick().
     *
     * @var list<Element>
     */
    private array $seen = [];

    /**
     * The app's database as it was before the last step that was not a database check, so
     * the page lists what that step wrote. Null until it was first read.
     *
     * @var Snapshot|null
     */
    private ?array $before = null;

    public function __construct(
        private readonly Driver $driver,
        private readonly Screen $screen,
        string $path,
        private readonly Selector $selector = new Selector,
    ) {
        $this->script = new Script($path);
    }

    public static function start(Device $device, string $path): self
    {
        $configuration = Configuration::resolve();
        $driver = Sessions::get($device);
        $driver->ensureReady();
        SystemUi::close($driver);
        // A run stopped with Ctrl+C leaves its fakes in the app. Outside a test this only
        // takes them away, so the recording sees what the app really does.
        FakeSync::sync($driver);
        $driver->open($configuration->urlFor($path));

        $failures = sys_get_temp_dir().'/simulator-codegen';

        return new self($driver, new Screen(
            $driver,
            timeoutSeconds: $configuration->timeout(),
            failureDirectory: $failures,
            firstLaunchSeconds: $driver->freshlyInstalled() ? Screen::FIRST_LAUNCH_SECONDS : 0.0,
        ), $path);
    }

    /**
     * What the page draws: the screen's size in the driver's own units, its controls, and the
     * test so far.
     *
     * @return array{viewport: array{0: float, 1: float}, elements: list<Element>, code: string, database: array{changes: list<Change>, error: string|null}}
     */
    public function state(): array
    {
        $this->seen = $this->elements();

        return [
            'viewport' => $this->driver->viewport(),
            'elements' => $this->seen,
            'code' => $this->script->chain(),
            'database' => $this->database(),
        ];
    }

    /**
     * Records a check on a row the last step wrote: assertDatabaseHas() with the columns it
     * wrote, or with $count, assertDatabaseCount() for its table.
     *
     * @return array{line: string}
     */
    public function checkRow(string $table, int $rowid, bool $count = false): array
    {
        $changes = $this->database()['changes'];
        $change = array_values(array_filter($changes, fn (array $change): bool => $change['table'] === $table && $change['rowid'] === $rowid))[0]
            ?? throw new SimulatorException("The last step did not write that row of [{$table}].");

        $step = $count ? Changes::count($change) : (Changes::has($change)
            ?? throw new SimulatorException('That row only changed its id or timestamps, which a test cannot know ahead of time.'));

        return $this->run($step);
    }

    /**
     * The rows the last step added or changed, read from the device now, so a write that
     * lands after the step shows up too.
     *
     * @return array{changes: list<Change>, error: string|null}
     */
    private function database(): array
    {
        try {
            $now = $this->snapshot();
        } catch (Throwable $exception) {
            return ['changes' => [], 'error' => strtok($exception->getMessage(), "\n") ?: $exception::class];
        }

        $this->before ??= $now;

        return ['changes' => Changes::between($this->before, $now), 'error' => null];
    }

    /**
     * @return Snapshot
     */
    private function snapshot(): array
    {
        // One pull serves a Screen step. Here each read is a new look, and the digest keeps
        // it from pulling a database the app has not written to.
        DeviceDatabase::forget();

        return Changes::snapshot(DeviceDatabase::open($this->driver));
    }

    public function screenshot(): string
    {
        $path = sys_get_temp_dir().'/codegen-'.bin2hex(random_bytes(8)).'.png';

        try {
            $this->driver->screenshot($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Records a click at a point, in the driver's units: a touch, or with $check, an
     * assertion about what is there.
     *
     * @return array{line: string}
     */
    public function click(float $x, float $y, bool $check = false, ?string $text = null): array
    {
        $elements = $this->elements();
        $element = $this->selector->at($elements, $x, $y, touch: ! $check)
            ?? throw new SimulatorException('Nothing the plugin can read is there.');
        // Only a step that names its control needs a name: assertSee() takes the text.
        $at = fn (): array => $this->selector->for($elements, $element);

        return $this->run($check ? Verb::check($element, $at) : Verb::touch($element, $at, $text));
    }

    /**
     * The control a click at a point would record, from the last state() read, so the page
     * outlines the same one, and asks for text only when the click lands on a field.
     *
     * @return array{element: Element|null, field: bool}
     */
    public function pick(float $x, float $y, bool $check = false): array
    {
        $element = $this->selector->at($this->seen, $x, $y, touch: ! $check);

        return ['element' => $element, 'field' => ! $check && $element !== null && Verb::field($element)];
    }

    /**
     * Records a step that no control names: scrolling, going back, closing a sheet.
     *
     * @return array{line: string}
     */
    public function press(string $button): array
    {
        return $this->run(match ($button) {
            'scroll-down' => new Step('scroll', ['down']),
            'scroll-up' => new Step('scroll', ['up']),
            'back' => new Step('goBack'),
            'dismiss' => new Step('dismissSheet'),
            default => throw new SimulatorException("Unknown button [{$button}]."),
        });
    }

    /**
     * @return array{line: string}
     */
    private function run(Step $step): array
    {
        // A check changes nothing, so the list stays on what the last action wrote, and
        // its rows can be checked after an assertSee() or one after another.
        if (! str_starts_with($step->verb, 'assert')) {
            try {
                $this->before = $this->snapshot();
            } catch (Throwable) {
                $this->before = null;
            }
        }

        try {
            $step->perform($this->screen);
        } catch (Throwable $exception) {
            // The whole failure lists every control on screen, which the page does not need.
            throw new SimulatorException(strtok($exception->getMessage(), "\n") ?: $exception::class, previous: $exception);
        }

        $this->script->add($step);

        return ['line' => $step->code()];
    }

    /**
     * @return list<Element>
     */
    private function elements(): array
    {
        return IdleMarker::split($this->driver->describe())[0];
    }
}
