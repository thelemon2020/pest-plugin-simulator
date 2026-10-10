<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Pest\TestSuite;

/**
 * The steps one test took on the device: each Screen call, what it matched, where it
 * touched, what it waited for, and how long it took. A failed assertion saves it next to
 * tree.json and screen.png.
 *
 * A test can open more than one screen, so the steps belong to the test, not a Screen.
 */
final class Trace
{
    /** @var list<array<string, mixed>> */
    private static array $steps = [];

    private static ?float $began = null;

    public static function begin(): void
    {
        self::$steps = [];
        self::$began = microtime(true);
    }

    public static function reset(): void
    {
        self::$steps = [];
        self::$began = null;
    }

    /**
     * @param  array<string, mixed>  $step  `action`, `target`, `started` (microtime), `seconds`, `result`, and what else the step noted
     */
    public static function add(array $step): void
    {
        $started = (float) $step['started'];
        self::$began ??= $started;
        $step['started'] = round($started - self::$began, 3);
        $step['seconds'] = round((float) $step['seconds'], 3);

        foreach (['sheetWait', 'settleWait'] as $wait) {
            if (isset($step[$wait])) {
                $step[$wait] = round((float) $step[$wait], 3);
            }
        }

        self::$steps[] = $step;

        if (VerboseLog::enabled()) {
            VerboseLog::write($step['seconds'], (string) $step['result'], 'step '.self::line($step));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function steps(): array
    {
        return self::$steps;
    }

    /**
     * @return list<string> the files written
     */
    public static function write(string $directory): array
    {
        if (self::$steps === []) {
            return [];
        }

        $trace = ['test' => self::test(), 'device' => self::device(), 'steps' => self::$steps];
        $json = json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        $written = [];

        if (is_string($json) && @file_put_contents($directory.'/trace.json', $json."\n") !== false) {
            $written[] = $directory.'/trace.json';
        }

        if (@file_put_contents($directory.'/trace.txt', self::summary()) !== false) {
            $written[] = $directory.'/trace.txt';
        }

        return $written;
    }

    /**
     * One line per step: when it started, how long it took, how it ended, and what it did.
     */
    public static function summary(): string
    {
        $lines = self::test() === '' ? [] : [self::test(), ''];

        foreach (self::$steps as $step) {
            $lines[] = sprintf('%+9.3fs %8.3fs  %-6s  %s', $step['started'], $step['seconds'], $step['result'], self::line($step));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private static function line(array $step): string
    {
        $line = $step['action'].(isset($step['target']) ? " [{$step['target']}]" : '');
        $details = [];
        $match = $step['match'] ?? null;

        if (is_array($match)) {
            $details[] = 'matched '.($match['role'] ?? 'Element').' "'.$match['label'].'"'
                .(isset($match['id']) ? " #{$match['id']}" : '')
                .(is_array($match['center'] ?? null) ? ' at '.self::point($match['center']) : '');
        }

        $reads = (int) ($step['reads'] ?? 0);
        $failed = (int) ($step['failedReads'] ?? 0);

        if ($reads > 0) {
            $details[] = $reads.($reads === 1 ? ' read' : ' reads').($failed > 0 ? " ({$failed} failed)" : '');
        }

        $scrolls = $step['scrolls'] ?? [];

        if (is_array($scrolls) && $scrolls !== []) {
            $details[] = 'scrolled '.implode(', ', array_map(strval(...), $scrolls));
        }

        if (isset($step['attempts'])) {
            $details[] = 'typed '.($step['attempts'] === 1 ? 'once' : $step['attempts'].' times')
                .(($step['settled'] ?? false) ? '' : ', never settled');
        }

        foreach (['sheetWait' => 'for a sheet', 'settleWait' => 'for scrolling to stop'] as $key => $reason) {
            if (($step[$key] ?? 0) > 0) {
                $details[] = sprintf('waited %.2fs %s', $step[$key], $reason);
            }
        }

        if (isset($step['error'])) {
            $details[] = explode("\n", (string) $step['error'])[0];
        }

        return $details === [] ? $line : $line.': '.implode('; ', $details);
    }

    /**
     * @param  array<int, float|int>  $point
     */
    private static function point(array $point): string
    {
        return round((float) $point[0], 1).','.round((float) $point[1], 1);
    }

    /**
     * The running test's description, as Pest prints it.
     */
    public static function test(): string
    {
        $test = TestSuite::getInstance()->test;

        if ($test !== null && method_exists($test, 'getPrintableTestCaseMethodName')) {
            return (string) $test->getPrintableTestCaseMethodName();
        }

        return $test?->name() ?? '';
    }

    private static function device(): ?string
    {
        return Run::inside() ? Run::device()->platform.' '.Run::device()->name : null;
    }
}
