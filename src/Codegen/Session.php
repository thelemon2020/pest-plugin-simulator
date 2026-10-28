<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Codegen;

use Illuminate\Foundation\Application;
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
 * It opens the app as it is. Setup puts the rows a test starts from into the app first,
 * the way a test's first screen() copies in its database, and starts the recording over.
 *
 * @phpstan-import-type Element from Driver
 * @phpstan-import-type Snapshot from Changes
 * @phpstan-import-type Change from Changes
 * @phpstan-import-type ModelInfo from Models
 */
final class Session
{
    public readonly Script $script;

    /**
     * The rows applied to the app, which the test makes too, before its screen().
     */
    private Setup $setup;

    /** @var list<ModelInfo>|null */
    private ?array $models = null;

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

    /** The title of the screen the recording is on, as the nav bar shows it. */
    private ?string $title = null;

    public function __construct(
        private readonly Driver $driver,
        private readonly Screen $screen,
        string $path,
        private readonly Selector $selector = new Selector,
        private readonly ?string $modelsDirectory = null,
        private readonly string $modelsNamespace = 'App\\Models',
    ) {
        $this->script = new Script($path);
        $this->setup = new Setup([]);
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
        ), $path, ...self::modelsOf(function_exists('app') ? app() : null));
    }

    /**
     * Where a Laravel app keeps its models. Outside one, as in this package's own tests,
     * there are none to set up.
     *
     * @return array{modelsDirectory?: string, modelsNamespace?: string}
     */
    private static function modelsOf(mixed $app): array
    {
        if (! $app instanceof Application) {
            return [];
        }

        return ['modelsDirectory' => $app->path('Models'), 'modelsNamespace' => $app->getNamespace().'Models'];
    }

    /**
     * What the page draws: the screen's size in the driver's own units, its controls, and the
     * test so far.
     *
     * @return array{viewport: array{0: float, 1: float}, elements: list<Element>, code: string, database: array{changes: list<Change>, error: string|null}, setup: list<array{model: string, rows: int}>, setupCode: string}
     */
    public function state(): array
    {
        $this->seen = $this->elements();
        $this->notice($this->seen);

        return [
            'viewport' => $this->driver->viewport(),
            'elements' => $this->seen,
            'code' => $this->script->chain(),
            'database' => $this->database(),
            'setup' => array_map(fn (array $entry): array => ['model' => $entry['model']['name'], 'rows' => count($entry['rows'])], $this->setup->entries),
            'setupCode' => $this->setupCode(),
        ];
    }

    /**
     * The app's models a test can start with rows of.
     *
     * @return list<ModelInfo>
     */
    public function models(): array
    {
        return $this->models ??= $this->modelsDirectory === null ? [] : Models::in($this->modelsDirectory, $this->modelsNamespace);
    }

    /**
     * $count rows of a model to start from, for the page to show and edit.
     *
     * @return list<array<string, string|null>>
     */
    public function rows(string $class, int $count): array
    {
        return Models::rows($this->model($class), max(1, min($count, 100)));
    }

    /**
     * Puts the rows into the app and opens the first screen again. The recording starts
     * over, since its steps were made against the rows that were there before.
     *
     * @param  array<array-key, mixed>  $entries  [{class, factory?: bool, fields?: [name], rows: [{column: value}]}], as the page sends them
     */
    public function applySetup(array $entries): void
    {
        $setup = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['class'] ?? null)) {
                continue;
            }

            $fields = is_array($entry['fields'] ?? null) ? array_values(array_filter($entry['fields'], is_string(...))) : [];
            $model = Models::withFields($this->model($entry['class']), $fields);
            $factory = ($entry['factory'] ?? false) === true;

            if ($factory && ! $model['factory']) {
                throw new SimulatorException("{$model['name']} has no factory to use.");
            }

            $rows = array_values(array_map(
                fn (mixed $row): array => Models::values($model, is_array($row) ? $row : [], $factory),
                is_array($entry['rows'] ?? null) ? $entry['rows'] : [],
            ));

            if ($rows !== []) {
                $setup[] = ['model' => $model, 'rows' => $rows, 'factory' => $factory];
            }
        }

        $setup = new Setup($setup);
        $setup->apply($this->driver);
        $this->setup = $setup;

        $this->driver->open(Configuration::resolve()->urlFor($this->script->path));
        DeviceDatabase::forget();
        $this->script->clear();
        $this->title = null;
        $this->before = null;
    }

    /**
     * The setup lines for what was applied, with their use lines.
     */
    private function setupCode(): string
    {
        ['uses' => $uses, 'code' => $code] = $this->setup->code();

        return $code === '' ? '' : implode("\n", $uses)."\n\n".$code;
    }

    /**
     * One of the app's models, by its class: only a model the page was offered.
     *
     * @return ModelInfo
     */
    private function model(string $class): array
    {
        foreach ($this->models() as $model) {
            if ($model['class'] === $class) {
                return $model;
            }
        }

        throw new SimulatorException("[{$class}] is not one of the app's models.");
    }

    /**
     * Notes a screen with another title in the test, so a long recording reads screen by
     * screen. Only a nav bar's title counts: a screen with none keeps the last one.
     *
     * @param  list<Element>  $elements
     */
    private function notice(array $elements): void
    {
        foreach ($elements as $element) {
            if (($element['chrome'] ?? null) === 'navigation' && $element['role'] !== 'Button' && ! in_array($element['label'], ['', 'Back'], true)) {
                if ($element['label'] !== $this->title) {
                    $this->title = $element['label'];
                    $this->script->screen($element['label']);
                }

                return;
            }
        }
    }

    /**
     * Records a check on a row the last action wrote: assertDatabaseHas() on $columns, or on
     * the ones it suggests, or with $count, assertDatabaseCount() for its table.
     *
     * @param  list<string>|null  $columns
     * @return array{line: string}
     */
    public function checkRow(string $table, int $rowid, bool $count = false, ?array $columns = null): array
    {
        $changes = $this->database()['changes'];
        $change = array_values(array_filter($changes, fn (array $change): bool => $change['table'] === $table && $change['rowid'] === $rowid))[0]
            ?? throw new SimulatorException("The last step did not write that row of [{$table}].");

        $step = $count ? Changes::count($change) : (Changes::has($change, $columns)
            ?? throw new SimulatorException($columns === null
                ? 'That row only changed its id or timestamps, which a test cannot know ahead of time. Pick the columns to check.'
                : 'Pick at least one column to check.'));

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
        $this->notice($elements);
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
            'relaunch' => new Step('relaunch'),
            default => throw new SimulatorException("Unknown button [{$button}]."),
        });
    }

    /**
     * Records opening another route in the app that is running, by its deep link.
     *
     * @return array{line: string}
     */
    public function open(string $path): array
    {
        if (! str_starts_with($path, '/')) {
            throw new SimulatorException("Open a route by its path, starting with /: not [{$path}].");
        }

        return $this->run(new Step('openUrl', [$path]));
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
