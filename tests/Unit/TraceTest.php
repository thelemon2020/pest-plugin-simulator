<?php

declare(strict_types=1);

use NativePhp\Simulator\AccessibilityTree;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Screen;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\TestDatabase;
use NativePhp\Simulator\Trace;
use NativePhp\Simulator\VerboseLog;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeDriver;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/simulator-trace-'.uniqid('', true);
});

afterEach(function () {
    VerboseLog::to(null);

    foreach (glob($this->directory.'/*') ?: [] as $file) {
        unlink($file);
    }

    if (is_dir($this->directory)) {
        rmdir($this->directory);
    }
});

/**
 * @return array{label: string, role: string, id: ?string, center: array{0: float, 1: float}}
 */
function traced(string $label, float $x = 100.0, float $y = 200.0, string $role = 'Button'): array
{
    return ['label' => $label, 'role' => $role, 'id' => null, 'center' => [$x, $y]];
}

/**
 * @return array{0: string, 1: array<string, mixed>}
 */
function failWithTrace(Screen $screen, Closure $steps): array
{
    try {
        $steps($screen);
    } catch (AssertionFailedError $error) {
        return [$error->getMessage(), Trace::steps()];
    }

    throw new RuntimeException('The steps did not fail.');
}

it('saves the trace next to the tree and screenshot, and names it in the failure', function () {
    $driver = new FakeDriver([[traced('Save', 120.0, 640.0), traced('Name', role: 'TextField')]]);
    $screen = new Screen($driver, timeoutSeconds: 0, failureDirectory: $this->directory);

    [$message] = failWithTrace($screen, fn (Screen $screen) => $screen->tap('Save')->assertSee('Saved'));

    $trace = json_decode((string) file_get_contents($this->directory.'/trace.json'), true);

    expect($message)
        ->toContain('Saved '.$this->directory.'/tree.json')
        ->toContain('Saved '.$this->directory.'/screen.png')
        ->toContain('Saved '.$this->directory.'/trace.json')
        ->toContain('Saved '.$this->directory.'/trace.txt')
        ->and(array_column($trace['steps'], 'action'))->toBe(['tap', 'assertSee'])
        ->and($trace['steps'][0])->toMatchArray([
            'target' => 'Save',
            'result' => 'ok',
            'reads' => 1,
            'failedReads' => 0,
            'match' => ['label' => 'Save', 'role' => 'Button', 'id' => null, 'center' => [120.0, 640.0]],
            'sent' => [['tap', 120.0, 640.0]],
        ])
        ->and($trace['steps'][1])->toMatchArray([
            'target' => 'Saved',
            'result' => 'failed',
            'error' => 'Did not see [Saved].',
            'reads' => 1,
        ])
        ->and($trace['steps'][1]['seconds'])->toBeFloat()
        ->and($trace['steps'][1]['started'])->toBeGreaterThanOrEqual($trace['steps'][0]['started']);
});

it('writes a short summary, one line per step', function () {
    $driver = new FakeDriver([[traced('Save', 120.0, 640.0)]]);
    $screen = new Screen($driver, timeoutSeconds: 0, failureDirectory: $this->directory);

    failWithTrace($screen, fn (Screen $screen) => $screen->tap('Save')->assertSee('Saved'));

    $lines = file($this->directory.'/trace.txt', FILE_IGNORE_NEW_LINES) ?: [];
    $steps = array_values(array_filter($lines, fn (string $line): bool => preg_match('/^\s*\+\d/', $line) === 1));

    expect($steps)->toHaveCount(2)
        ->and($steps[0])->toMatch('/^\s+\+0\.000s\s+\d+\.\d{3}s  ok      tap \[Save\]: matched Button "Save" at 120,640; 1 read$/')
        ->and($steps[1])->toMatch('/s  failed  assertSee \[Saved\]: 1 read; Did not see \[Saved\]\.$/');
});

it('counts the reads an assertion waited through, and the ones that failed', function () {
    $driver = new FakeDriver([
        [traced('Loading')],
        [traced('Loading')],
        [traced('Saved')],
    ]);
    $driver->describeFailures = 1;

    (new Screen($driver, timeoutSeconds: 3))->assertSee('Saved');

    expect(Trace::steps()[0])->toMatchArray(['action' => 'assertSee', 'reads' => 4, 'failedReads' => 1, 'result' => 'ok']);
});

it('notes the scrolls that brought a control on screen', function () {
    $driver = new FakeDriver([
        [traced('Row 40', 100.0, 1000.0)],
        [traced('Row 40', 100.0, 500.0)],
    ]);

    (new Screen($driver, timeoutSeconds: 0))->tap('Row 40');

    $step = Trace::steps()[0];

    expect($step['scrolls'])->toBe(['down'])
        ->and($step['match']['center'])->toBe([100.0, 500.0])
        ->and(array_column($step['sent'], 0))->toBe(['swipe', 'tap'])
        ->and($step['sent'][1])->toBe(['tap', 100.0, 500.0]);
});

it('notes how many times type() typed and whether the field settled', function () {
    $driver = new FakeDriver([[traced('Email', 200.0, 300.0, 'TextField')]]);

    (new Screen($driver, timeoutSeconds: 0))->type('Email', 'ada@example.com');

    expect(Trace::steps()[0])->toMatchArray([
        'action' => 'type',
        'attempts' => 3,
        'settled' => false,
    ])->and(array_column(Trace::steps()[0]['sent'], 0))->toBe(['tap', 'clear', 'text', 'tap', 'clear', 'text', 'tap', 'clear', 'text']);
});

it('keeps a password out of the trace', function () {
    $fixtures = dirname(__DIR__).'/Fixtures';
    $driver = new FakeDriver([
        AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-secure-field-empty.json')),
        AccessibilityTree::summarize((string) file_get_contents($fixtures.'/ios-secure-field-typed.json')),
    ]);

    (new Screen($driver, timeoutSeconds: 1))->type('Password', 'secret');

    expect($driver->texts)->toBe(['secret'])
        ->and(Trace::steps()[0]['sent'])->toContain(['text', '••••••'])
        ->and(json_encode(Trace::steps()))->not->toContain('secret');
});

it('notes the wait for a sheet to slide in', function () {
    $sheet = AccessibilityTree::summarize((string) file_get_contents(dirname(__DIR__).'/Fixtures/ios-note-sheet-over-tab-bar.json'));
    $driver = new FakeDriver([[traced('+ Add a note')], $sheet]);

    (new Screen($driver, timeoutSeconds: 5))->tap('+ Add a note')->tap('😌 Chill');

    [$opening, $intoSheet] = Trace::steps();

    expect($opening)->not->toHaveKey('sheetWait')
        ->and($intoSheet['sheetWait'])->toBeGreaterThan(0.5)
        ->and($intoSheet['seconds'])->toBeGreaterThanOrEqual($intoSheet['sheetWait']);
});

it('marks a step the device broke as an error', function () {
    $driver = new FakeDriver([[traced('Save')]]);
    $driver->describeFailures = 5;

    expect(fn () => (new Screen($driver, timeoutSeconds: 0))->scroll())->toThrow(SimulatorException::class);

    expect(Trace::steps()[0])->toMatchArray([
        'action' => 'scroll',
        'target' => 'down',
        'result' => 'error',
        'error' => 'window-server frontmost returned no application object',
        'failedReads' => 1,
    ]);
});

it('logs each step when --simulator-verbose is on', function () {
    $log = $this->directory.'/verbose.log';
    mkdir($this->directory);
    VerboseLog::to($log);

    (new Screen(new FakeDriver([[traced('Save')]]), timeoutSeconds: 0))->tap('Save');

    expect(file_get_contents($log))->toMatch('/s\s+ok\s+step tap \[Save\]: matched Button "Save" at 100,200; 1 read\n$/');
});

mobile(function () {
    beforeEach(function () {
        $this->driver = new FakeDriver([[traced('Save')]]);
        $this->database = (string) tempnam(sys_get_temp_dir(), 'simulator-');
        Configuration::configure(['scheme' => 'myapp']);
        Sessions::fake($this->driver);
        TestDatabase::fake(fn (): string => $this->database);
    });

    afterEach(function () {
        Sessions::fake(null);
        TestDatabase::fake(null);
        Configuration::reset();

        if (is_file($this->database)) {
            unlink($this->database);
        }
    });

    it('keeps one trace for the whole test, across screens', function () {
        permissions([]);

        screen('/lights')->tap('Save');
        screen('/settings')->assertSee('Save');

        expect(array_map(
            fn (array $step): string => $step['action'].' '.$step['target'],
            Trace::steps(),
        ))->toBe(['open myapp://lights', 'tap Save', 'open myapp://settings', 'assertSee Save'])
            ->and(Trace::summary())->toStartWith('it keeps one trace for the whole test, across screens');
    });

    it('starts each test with an empty trace', function () {
        permissions([]);

        screen('/lights');

        expect(Trace::steps())->toHaveCount(1);
    });
});
