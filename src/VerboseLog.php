<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

/**
 * What --simulator-verbose writes: one line per external command, companion call, test,
 * and Screen step, with how long it took and how it ended.
 *
 * It is a file, not STDERR. ParaTest keeps a worker's STDERR to itself unless the worker
 * crashes, so a parallel run would show nothing. Every process appends to the same file,
 * and each line names its worker, so lines from a slow worker sit next to whatever the
 * others were doing at the time.
 */
final class VerboseLog
{
    public const string DEFAULT = 'simulator-logs/verbose.log';

    private static ?string $path = null;

    public static function to(?string $path): void
    {
        self::$path = $path;
    }

    /**
     * Empty the file for a new run. Only the process the run started in does this: lanes
     * and workers append to what it began.
     */
    public static function start(string $path, string $command): void
    {
        self::$path = $path;
        self::ensureDirectory($path);
        @file_put_contents($path, sprintf("%s pest run: %s\n", date('Y-m-d H:i:s'), $command));
    }

    public static function path(): ?string
    {
        return self::$path;
    }

    public static function enabled(): bool
    {
        return self::$path !== null;
    }

    /**
     * @param  string  $status  how it ended, such as `exit 0`, `grpc 2`, or `timed out`
     */
    public static function write(float $seconds, string $status, string $what): void
    {
        if (self::$path === null) {
            return;
        }

        self::line(sprintf('%8.3fs  %-9s  %s', $seconds, $status, self::oneLine($what)));
    }

    public static function note(string $what): void
    {
        if (self::$path === null) {
            return;
        }

        self::line(str_repeat(' ', 22).self::oneLine($what));
    }

    /**
     * The command as a shell would take it, so a line can be copied and run again.
     *
     * @param  list<string>  $command
     */
    public static function command(array $command): string
    {
        return implode(' ', array_map(
            fn (string $part): string => preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#', $part) === 1 ? $part : escapeshellarg($part),
            $command,
        ));
    }

    private static function line(string $text): void
    {
        if (self::$path === null) {
            return;
        }

        $now = microtime(true);
        $worker = Worker::parallel() ? 'w'.Worker::index() : 'main';
        self::ensureDirectory(self::$path);
        // One write per line, appended under a lock, so workers never split each other's lines.
        @file_put_contents(
            self::$path,
            sprintf("%s.%03d %-6s %s\n", date('H:i:s', (int) $now), (int) (($now - floor($now)) * 1000), "[{$worker}]", $text),
            FILE_APPEND | LOCK_EX,
        );
    }

    private static function oneLine(string $text): string
    {
        $text = trim((string) preg_replace('/\s*\R\s*/', ' | ', $text));

        return mb_strlen($text) > 400 ? mb_substr($text, 0, 400).'…' : $text;
    }

    private static function ensureDirectory(string $path): void
    {
        $directory = dirname($path);

        if ($directory !== '' && $directory !== '.' && ! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }
    }
}
