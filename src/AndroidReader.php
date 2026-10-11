<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;

/**
 * The screen reader AndroidDriver keeps running on the emulator, built from android/src.
 *
 * `uiautomator dump` starts a process on the device, waits for the screen to sit still for
 * a whole second, writes one dump, and exits: two seconds a read, every read. The reader
 * connects to the accessibility tree once, through the device's own uiautomator.jar, and
 * answers each "dump" with a length line and the same XML. It waits for the same second of
 * stillness, which a screen that has been still already has, so only a read right after a
 * tap or a scroll waits. A shorter wait read a sheet's screen before the sheet opened.
 *
 * Only one process at a time can hold the device's accessibility connection, so the reader
 * is stopped before AndroidDriver falls back to `uiautomator dump`. After three failures in
 * a row it is not started again.
 */
final class AndroidReader
{
    public const string REMOTE = '/data/local/tmp/pest-simulator-reader.jar';

    private const int ATTEMPTS = 3;

    private ?RunningCommand $process = null;

    private int $failures = 0;

    /**
     * @param  Closure(): ?RunningCommand  $start  copies the jar to the device and runs it
     * @param  float  $startSeconds  a cold emulator on CI can take seconds to start app_process
     * @param  float  $readSeconds  the reader waits up to 10 seconds for the screen to settle, then walks the tree
     */
    public function __construct(
        private readonly Closure $start,
        private readonly float $startSeconds = 15.0,
        private readonly float $readSeconds = 15.0,
    ) {}

    public static function jar(): string
    {
        return dirname(__DIR__).'/android/reader.jar';
    }

    /**
     * The device shell command that runs the reader.
     */
    public static function launch(): string
    {
        return 'CLASSPATH=/system/framework/uiautomator.jar:'.self::REMOTE.' app_process /system/bin nativephp.simulator.Reader';
    }

    /**
     * The screen as `uiautomator dump` writes it, an empty string when no window has focus,
     * or null when the reader could not answer.
     */
    public function dump(): ?string
    {
        if ($this->failures >= self::ATTEMPTS) {
            return null;
        }

        $process = $this->process ?? $this->open();

        if ($process === null || ! $process->write("dump\n")) {
            return $this->failed('did not start');
        }

        $length = $process->line($this->readSeconds);

        if ($length === '-1') {
            return '';
        }

        if ($length === null || ! ctype_digit($length)) {
            return $this->failed('did not answer');
        }

        $xml = $process->bytes((int) $length, $this->readSeconds);

        if ($xml === null) {
            return $this->failed('stopped answering');
        }

        $this->failures = 0;

        return $xml;
    }

    public function stop(): void
    {
        $this->process?->close();
        $this->process = null;
    }

    private function open(): ?RunningCommand
    {
        $started = microtime(true);
        $process = ($this->start)();

        if ($process === null) {
            return null;
        }

        if ($process->line($this->startSeconds) !== 'ready') {
            $process->close();

            return null;
        }

        VerboseLog::write(microtime(true) - $started, 'ready', 'Android screen reader');

        return $this->process = $process;
    }

    private function failed(string $why): null
    {
        $this->stop();
        $this->failures++;
        VerboseLog::write(0.0, 'failed', "Android screen reader {$why}; reading with uiautomator dump");

        return null;
    }
}
