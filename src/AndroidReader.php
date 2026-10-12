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
 * It types for AndroidDriver too, so typing does not start an `input` process per
 * character: key presses into the focused field, a paste from a clipboard it sets itself
 * for what has no key, emptying that field, and closing the software keyboard. When it
 * cannot, AndroidDriver types with `adb shell input`.
 *
 * Only one process at a time can hold the device's accessibility connection, so the reader
 * is stopped before AndroidDriver falls back to `uiautomator dump`. After three failures in
 * a row it is not started again.
 */
final class AndroidReader
{
    public const string REMOTE = '/data/local/tmp/pest-simulator-reader.jar';

    private const int ATTEMPTS = 3;

    /**
     * Milliseconds before each key. The reader doubles it whenever the field shows an older
     * render of its text (see android/src/nativephp/simulator/Keyboard.java).
     */
    public const int PAUSE = 20;

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
        $process = $this->send('dump');

        if ($process === null) {
            return null;
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

    /**
     * Types the text into the focused field: key presses for printable ASCII, and a paste
     * for each run of anything else, a new line too. False when it did not: the reader is
     * not running, the device's key map has no key for a character or the clipboard cannot
     * be set (nothing is typed), or a key could not be sent.
     */
    public function type(string $text): bool
    {
        // Each key waits for the field to show it, longer when the app is slow. The log
        // leaves the text out, as the trace leaves out a password.
        return $this->ask('type '.self::PAUSE.' '.base64_encode($text), $this->readSeconds + 3 * strlen($text), 'type '.mb_strlen($text).' characters');
    }

    /**
     * Empties the focused field. False when no field is focused or the reader is not running.
     */
    public function clear(): bool
    {
        return $this->ask('clear', $this->readSeconds, 'clear');
    }

    /**
     * Closes the software keyboard when it is up. False when the reader is not running, or
     * could not press Back.
     */
    public function hideKeyboard(): bool
    {
        return $this->ask('hide', $this->readSeconds, 'hide the keyboard');
    }

    public function stop(): void
    {
        $this->process?->close();
        $this->process = null;
    }

    /**
     * The running reader, once it has the request. Null when it could not be started or has
     * stopped reading.
     */
    private function send(string $request): ?RunningCommand
    {
        if ($this->failures >= self::ATTEMPTS) {
            return null;
        }

        $process = $this->process ?? $this->open();

        if ($process === null || ! $process->write($request."\n")) {
            return $this->failed('did not start');
        }

        return $process;
    }

    /**
     * True when the reader answers "ok", false for "-1", and false when it does not answer.
     */
    private function ask(string $request, float $seconds, string $what): bool
    {
        $process = $this->send($request);

        if ($process === null) {
            return false;
        }

        $started = microtime(true);
        $answer = $process->line($seconds);

        if ($answer === null) {
            $this->failed('did not answer');

            return false;
        }

        $this->failures = 0;
        VerboseLog::write(microtime(true) - $started, $answer, "Android screen reader: {$what}");

        return $answer === 'ok';
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
        VerboseLog::write(0.0, 'failed', "Android screen reader {$why}; using uiautomator dump and adb shell input");

        return null;
    }
}
