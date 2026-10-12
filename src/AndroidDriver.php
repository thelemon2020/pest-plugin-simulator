<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

/**
 * @phpstan-import-type Element from Driver
 */
final class AndroidDriver implements Driver
{
    use RemembersReadiness;
    use RestoresSettings;

    private const RECORDING = '/sdcard/pest-simulator-recording.mp4';

    /**
     * Google's Mountain View campus, the emulator's usual starting point, for a device that
     * has no GPS fix to put back.
     */
    private const START_LOCATION = '37.4219983,-122.084';

    private const DEMO = 'am broadcast -a com.android.systemui.demo -e command';

    /** How long System UI is given to rebuild the status bar (see enterDemoMode()). */
    private static float $redrawSeconds = 2.0;

    /** @var array<string, true> */
    private static array $built = [];

    private static ?string $privateSerial = null;

    private static ?string $privateDevice = null;

    /** @var array<string, true> */
    private static array $wiped = [];

    private ?string $serial = null;

    private ?int $recordingPid = null;

    private ?string $recordingPath = null;

    /** @var array{0: float, 1: float} */
    private array $viewport = [390.0, 844.0];

    private ?int $emulatorPid = null;

    private ?AndroidReader $reader = null;

    private ?string $readerSerial = null;

    /** When a night mode or font scale change last made System UI rebuild the status bar. */
    private ?float $redrawnAt = null;

    /**
     * Set by clear(), which leaves the software keyboard up. The text() that usually comes
     * next opens it again anyway, and keys typed while it slides away land slower. So the
     * keyboard is closed before the next read or screenshot instead.
     */
    private bool $keyboardLeftUp = false;

    public function __construct(
        private readonly Device $device,
        private readonly Configuration $configuration,
        private readonly Command $command = new Command,
    ) {}

    public function ensureReady(): void
    {
        $parallel = Worker::parallel();

        if ($parallel) {
            $this->bootForWorker();
        } elseif (self::$ready !== $this) {
            // Booting kills the other emulators. If anything after that fails, the driver
            // trusted until now would skip its boot path against one that has exited.
            self::$ready = null;
            $this->bootShared();
        }

        $this->buildOnce();

        if (! $parallel) {
            self::$ready = $this;
        }
    }

    /**
     * adb answers "device" for an emulator that is up, and fails for a serial that has
     * gone.
     */
    private function alive(): bool
    {
        try {
            return trim($this->command->run($this->adb(), ['-s', $this->serial(), 'get-state'])) === 'device';
        } catch (SimulatorException) {
            return false;
        }
    }

    private function bootShared(): void
    {
        $booted = $this->bootedAvds();

        foreach (BootPlan::shutdowns($this->device->named, $this->device->name, array_keys($booted)) as $name) {
            $this->command->run($this->adb(), ['-s', $booted[$name], 'emu', 'kill']);
        }

        if ($this->shouldWipe()) {
            $this->wipe($booted);

            return;
        }

        if (! $this->device->named && count($booted) === 1) {
            $this->serial = array_values($booted)[0];
        } else {
            $this->bootNamed($booted);
        }
    }

    public function installDatabase(string $sqlitePath): void
    {
        $this->recovering(fn () => $this->pushDatabase($sqlitePath));
    }

    private function pushDatabase(string $sqlitePath): void
    {
        $bundle = $this->configuration->bundleId();
        $serial = $this->serial();
        $adb = $this->adb();
        $remote = '/data/local/tmp/pest-simulator-'.bin2hex(random_bytes(8)).'.sqlite';
        $directory = 'app_storage/persisted_data/database';
        $database = $directory.'/database.sqlite';

        $this->command->run($adb, ['-s', $serial, 'shell', 'am', 'force-stop', $bundle]);

        try {
            $this->command->run($adb, ['-s', $serial, 'push', $sqlitePath, $remote]);
            // One shell: chmod, copy into the app, drop the wal sidecars, and
            // delete the temp file even when the copy fails.
            $this->command->run($adb, ['-s', $serial, 'shell', $this->installScript($bundle, $remote, $directory, $database)]);
        } catch (SimulatorException $exception) {
            try {
                $this->command->run($adb, ['-s', $serial, 'shell', 'rm', '-f', $remote]);
            } catch (SimulatorException) {
            }

            throw $exception;
        }
    }

    private function installScript(string $bundle, string $remote, string $directory, string $database): string
    {
        $quotedBundle = escapeshellarg($bundle);
        $quotedRemote = escapeshellarg($remote);
        $quotedDirectory = escapeshellarg($directory);
        $quotedDatabase = escapeshellarg($database);
        $install = implode(' && ', [
            'chmod 644 '.$quotedRemote,
            'run-as '.$quotedBundle.' mkdir -p '.$quotedDirectory,
            'run-as '.$quotedBundle.' cp '.$quotedRemote.' '.$quotedDatabase,
            'run-as '.$quotedBundle.' rm -f '.escapeshellarg($database.'-wal').' '.escapeshellarg($database.'-shm'),
        ]);

        return '('.$install.'); status=$?; rm -f '.$quotedRemote.'; exit $status';
    }

    public function open(string $url): void
    {
        $this->recovering(fn () => $this->openUrl($url));
    }

    private function openUrl(string $url): void
    {
        try {
            // adb joins the arguments into one line for the device shell, where an & in the
            // query would end the command.
            $this->command->run($this->adb(), [
                '-s', $this->serial(), 'shell', 'am', 'start',
                '-a', 'android.intent.action.VIEW',
                '-d', escapeshellarg($url),
                $this->configuration->bundleId(),
            ]);
        } catch (SimulatorException $exception) {
            // am start exits 1 when no activity in the app takes the link. The app is then
            // started as its launcher icon does.
            if (! str_contains($exception->getMessage(), 'unable to resolve Intent')) {
                throw $exception;
            }

            $this->launchApp();
        }
    }

    public function describe(?string $treePath = null): array
    {
        $this->closeKeyboardLeftUp();

        return $this->dismissSystemDialog($this->elementsFrom($this->dumpHierarchy(), $treePath), 0);
    }

    /**
     * @param  list<Element>  $elements
     * @return list<Element>
     */
    private function dismissSystemDialog(array $elements, int $attempt): array
    {
        if ($attempt >= 2) {
            return $elements;
        }

        $wait = $this->notRespondingWait($elements);

        if ($wait === null) {
            return $elements;
        }

        $this->tap((float) $wait[0], (float) $wait[1]);
        usleep(1_000_000);

        return $this->dismissSystemDialog($this->elementsFrom($this->dumpHierarchy()), $attempt + 1);
    }

    /**
     * The Wait button of an "isn't responding" dialog. Its resource id is the same in
     * every language. The English label covers a dump that leaves the id out.
     *
     * @param  list<Element>  $elements
     * @return ?array{0: float, 1: float}
     */
    private function notRespondingWait(array $elements): ?array
    {
        foreach ($elements as $element) {
            if ($element['id'] === 'android:id/aerr_wait' && is_array($element['center'] ?? null)) {
                return $element['center'];
            }
        }

        foreach ($elements as $element) {
            if ($element['role'] === 'Button' && $element['label'] === 'Wait' && is_array($element['center'] ?? null)) {
                return $element['center'];
            }
        }

        return null;
    }

    private function dumpHierarchy(): string
    {
        $dump = $this->reader()->dump();

        if ($dump !== null) {
            return $dump;
        }

        try {
            $dump = $this->command->run($this->adb(), ['-s', $this->serial(), 'exec-out', 'uiautomator', 'dump', '/dev/tty']);
        } catch (SimulatorException) {
            $dump = '';
        }

        if (! str_contains($dump, '<hierarchy')) {
            $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'uiautomator', 'dump', '/sdcard/uidump.xml']);
            $dump = $this->command->run($this->adb(), ['-s', $this->serial(), 'exec-out', 'cat', '/sdcard/uidump.xml']);
        }

        return $dump;
    }

    /**
     * The reader for the emulator this driver is on. A new serial gets a new reader.
     */
    private function reader(): AndroidReader
    {
        $serial = $this->serial();

        if ($this->reader === null || $this->readerSerial !== $serial) {
            $this->reader?->stop();
            $this->readerSerial = $serial;
            $this->reader = new AndroidReader(fn (): ?RunningCommand => $this->startReader($serial));
        }

        return $this->reader;
    }

    private function startReader(string $serial): ?RunningCommand
    {
        try {
            $this->command->run($this->adb(), ['-s', $serial, 'push', AndroidReader::jar(), AndroidReader::REMOTE]);
        } catch (SimulatorException) {
            return null;
        }

        return $this->command->open($this->adb(), ['-s', $serial, 'shell', AndroidReader::launch()]);
    }

    public function tap(float $x, float $y): void
    {
        $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'input', 'tap', (string) $x, (string) $y]);
    }

    public function press(float $x, float $y, float $seconds = 0.8): void
    {
        $this->swipe($x, $y, $x, $y, $seconds);
    }

    public function swipe(float $x1, float $y1, float $x2, float $y2, float $seconds = 0.3): void
    {
        $this->command->run($this->adb(), [
            '-s', $this->serial(), 'shell', 'input', 'swipe',
            (string) (int) round($x1),
            (string) (int) round($y1),
            (string) (int) round($x2),
            (string) (int) round($y2),
            (string) (int) round($seconds * 1000),
        ]);
    }

    public function back(): void
    {
        $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'input', 'keyevent', '4']);
    }

    /**
     * The reader empties the focused field in one change. Without it, the field is cleared
     * from the end with `adb shell input`.
     */
    public function clear(int $characters = 40): void
    {
        if (! $this->reader()->clear()) {
            $this->deleteFromEnd($characters);
        }

        $this->keyboardLeftUp = true;
    }

    private function deleteFromEnd(int $characters): void
    {
        // Select-all is Ctrl+A. On the emulator that modifier stays down, so the
        // next "input text" treats c as Copy and the character never lands.
        // Deletes are chunked so a long field is cleared without overflowing
        // the input queue.
        $remaining = max(1, $characters);

        while ($remaining > 0) {
            $count = min(40, $remaining);
            $this->command->run($this->adb(), [
                '-s', $this->serial(), 'shell', 'input', 'keyevent', '123',
                ...array_fill(0, $count, '67'),
            ]);
            $remaining -= $count;
        }
    }

    /**
     * The iOS Simulator types through a hardware keyboard, so no software keyboard covers
     * the screen there. Android's stays up after typing and covers the lower part of the
     * screen, and what is under it is not in the dump at all, so the next tap() could not
     * find a button below the field. The form also moves up while it is open. Back closes
     * only the keyboard while it is up. The field keeps its focus and its text.
     */
    private function hideKeyboard(): void
    {
        if ($this->reader()->hideKeyboard()) {
            return;
        }

        $this->command->run($this->adb(), [
            '-s', $this->serial(), 'shell',
            'if dumpsys input_method | grep mInputShown=true >/dev/null; then input keyevent 4; fi',
        ]);
    }

    private function closeKeyboardLeftUp(): void
    {
        if ($this->keyboardLeftUp) {
            $this->keyboardLeftUp = false;
            $this->hideKeyboard();
        }
    }

    /**
     * The reader types the whole text: key presses for ASCII, and a paste for the rest, a
     * new line too. When it cannot, `adb shell input` types ASCII and `cmd clipboard`
     * pastes the rest.
     */
    public function text(string $text): void
    {
        $this->keyboardLeftUp = false;

        if ($text !== '' && ! $this->reader()->type($text)) {
            $this->typeWithAdb($text);
        }

        $this->hideKeyboard();
    }

    private function typeWithAdb(string $text): void
    {
        $lines = explode("\n", $text);
        $last = count($lines) - 1;

        foreach ($lines as $index => $line) {
            $keys = '';

            foreach ($this->characters($line) as $character) {
                if ($this->onKeyboard($character)) {
                    $keys .= $character;

                    continue;
                }

                $this->typeKeys($keys);
                $keys = '';
                $this->pasteOrFail($character);
            }

            $this->typeKeys($keys);

            if ($index < $last) {
                $this->pasteOrFail("\n");
            }
        }
    }

    /**
     * Printable ASCII, with `adb shell input`.
     */
    private function typeKeys(string $text): void
    {
        // One character per `input text`. A single call with the whole string overflows
        // the emulator queue, and Compose drops letters. Those calls share one adb shell,
        // with a pause between them, so a word is not one process per letter. `;` keeps
        // going when one character fails.
        $batch = [];

        foreach (str_split($text) as $character) {
            if ($character === '@') {
                $this->flushText($batch);
                $batch = [];
                $this->typeAt();

                continue;
            }

            $batch[] = $character;
        }

        $this->flushText($batch);
    }

    /**
     * input text sends @ as Shift-2, and the software keyboard drops the letters around
     * that event. So the @ key is tapped when the dump shows the keyboard, and @ is
     * pasted when it does not. With no clipboard command either (android-36), the At key
     * is pressed. It lands there, on the email keyboard too.
     */
    private function typeAt(): void
    {
        if ($this->tapLabeled('@') || $this->paste('@')) {
            return;
        }

        $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'input', 'keyevent', '77']);
    }

    private function pasteOrFail(string $text): void
    {
        if ($this->paste($text)) {
            return;
        }

        $character = $text === "\n" ? 'a new line' : '['.$text.']';

        throw new SimulatorException("Cannot type {$character} on this emulator. The screen reader could not paste it, `input text` only types ASCII, and the emulator has no `cmd clipboard`.");
    }

    /**
     * @param  list<string>  $characters
     */
    private function flushText(array $characters): void
    {
        if ($characters === []) {
            return;
        }

        $commands = [];

        foreach ($characters as $character) {
            if ($commands !== []) {
                $commands[] = 'sleep 0.1';
            }

            $commands[] = 'input text '.AndroidText::argument($character);
        }

        // Each `input text` starts its own process on the device, which can
        // take most of a second on CI. A long line outlasts the default.
        $this->command->run(
            $this->adb(),
            ['-s', $this->serial(), 'shell', implode('; ', $commands)],
            timeout: Command::TIMEOUT + count($characters),
        );
    }

    private function onKeyboard(string $character): bool
    {
        return strlen($character) === 1 && ord($character) >= 32 && ord($character) <= 126;
    }

    private function tapLabeled(string $label): bool
    {
        foreach ($this->describe() as $element) {
            if ($element['label'] !== $label || ! is_array($element['center'] ?? null)) {
                continue;
            }

            $this->tap((float) $element['center'][0], (float) $element['center'][1]);

            return true;
        }

        return false;
    }

    /**
     * A system image with no clipboard command (android-36) says so on stderr and exits 0,
     * so nothing would land and nothing would fail.
     */
    private function paste(string $text): bool
    {
        $output = $this->command->run($this->adb(), [
            '-s', $this->serial(), 'shell', 'cmd', 'clipboard', 'set-text', AndroidText::argument($text), '2>&1',
        ]);

        if (str_contains($output, 'No shell command implementation') || str_contains($output, 'Unknown command')) {
            return false;
        }

        $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'input', 'keyevent', '279']);

        return true;
    }

    /**
     * @return list<string>
     */
    private function characters(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($characters === false) {
            throw new SimulatorException('Could not read the text.');
        }

        return $characters;
    }

    public function viewport(): array
    {
        return $this->viewport;
    }

    /**
     * No strip at the bottom of an Android screen has been seen to swallow a tap.
     */
    public function homeIndicator(): float
    {
        return 0.0;
    }

    public function screenshot(string $path): void
    {
        $this->closeKeyboardLeftUp();
        $png = $this->command->run($this->adb(), ['-s', $this->serial(), 'exec-out', 'screencap', '-p']);
        file_put_contents($path, $png);
    }

    public function startRecording(string $path): void
    {
        // record() comes before screen(), and screenrecord started against an emulator
        // that has gone writes nothing without failing here.
        $this->revive();
        $this->recordingPath = $path;
        // screenrecord will not write more than 180 seconds.
        $this->recordingPid = $this->command->start($this->adb(), [
            '-s', $this->serial(), 'shell', 'screenrecord', '--time-limit', '180', self::RECORDING,
        ], sys_get_temp_dir().'/pest-simulator-record-'.Worker::index().'.log');
    }

    public function stopRecording(): void
    {
        if ($this->recordingPid === null || $this->recordingPath === null) {
            return;
        }

        $pid = $this->recordingPid;
        $path = $this->recordingPath;
        $this->recordingPid = null;
        $this->recordingPath = null;
        $this->stopScreenRecord();

        if (! $this->command->wait($pid)) {
            $this->command->interrupt($pid);
        }

        try {
            $this->command->run($this->adb(), ['-s', $this->serial(), 'pull', self::RECORDING, $path]);
        } finally {
            try {
                $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'rm', '-f', self::RECORDING]);
            } catch (SimulatorException) {
            }
        }
    }

    public function grant(array $services): void
    {
        $this->recovering(fn () => $this->permission('grant', $services));
    }

    public function revoke(array $services): void
    {
        $this->recovering(fn () => $this->permission('revoke', $services));
    }

    /**
     * @param  list<string>  $services
     */
    private function permission(string $action, array $services): void
    {
        $bundle = $this->configuration->bundleId();

        foreach (Permissions::targets('android', $services) as $permission) {
            Permissions::attempt(fn () => $this->command->run($this->adb(), [
                '-s', $this->serial(), 'shell', 'pm', $action, $bundle, $permission,
            ]));
        }
    }

    public function captureLogs(string $directory): array
    {
        $bundle = $this->configuration->bundleId();
        $saved = [];
        $php = $this->read(['shell', 'run-as', $bundle, 'cat', 'app_storage/persisted_data/storage/logs/laravel.log']);

        if ($this->keep($php, $directory.'/laravel.log')) {
            $saved[] = $directory.'/laravel.log';
        }

        if ($this->keep($this->logcat($bundle), $directory.'/logcat.txt')) {
            $saved[] = $directory.'/logcat.txt';
        }

        return $saved;
    }

    public function appearance(?string $mode): void
    {
        $night = match ($mode === null ? null : DeviceState::appearance($mode)) {
            null => null,
            'dark' => 'yes',
            'light' => 'no',
        };

        $this->recovering(fn () => $this->setting(
            'appearance',
            $night,
            function (): string {
                $current = $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'cmd', 'uimode', 'night']);

                return preg_match('/night mode:\s*(yes|no|auto)\b/i', $current, $matches) === 1 ? strtolower($matches[1]) : 'no';
            },
            function (string $night): void {
                $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'cmd', 'uimode', 'night', $night]);
                $this->redrawn();
            },
        ));
    }

    /**
     * A font scale that was never set is deleted again rather than written as 1.0.
     */
    public function textSize(?string $size): void
    {
        $scale = $size === null ? null : DeviceState::textSize('android', $size);

        $this->recovering(fn () => $this->setting(
            'font_scale',
            $scale,
            fn (): string => $this->settingValue('system', 'font_scale'),
            function (string $scale): void {
                $this->command->run($this->adb(), $scale === 'null'
                    ? ['-s', $this->serial(), 'shell', 'settings', 'delete', 'system', 'font_scale']
                    : ['-s', $this->serial(), 'shell', 'settings', 'put', 'system', 'font_scale', $scale]);
                $this->redrawn();
            },
        ));
    }

    /**
     * The emulator console takes longitude first, and cannot report a location back. So
     * what is put back is the device's last GPS fix, which `dumpsys location` keeps once an
     * app has asked for one, and the emulator's starting point when none has.
     */
    public function location(?array $coordinates): void
    {
        $point = $coordinates === null ? null : DeviceState::number($coordinates[0]).','.DeviceState::number($coordinates[1]);

        $this->recovering(fn () => $this->setting(
            'location',
            $point,
            function (): string {
                $dump = $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'dumpsys', 'location']);

                return preg_match('/last location=Location\[gps (-?[\d.]+),(-?[\d.]+)/', $dump, $matches) === 1
                    ? $matches[1].','.$matches[2]
                    : self::START_LOCATION;
            },
            function (string $point): void {
                [$latitude, $longitude] = explode(',', $point);
                $this->command->run($this->adb(), ['-s', $this->serial(), 'emu', 'geo', 'fix', $longitude, $latitude]);
            },
        ));
    }

    /**
     * System UI's demo mode. Letting it go also puts back whether demo mode was allowed.
     */
    public function statusBar(bool $fixed): void
    {
        $this->recovering(fn () => $this->setting(
            'sysui_demo_allowed',
            $fixed ? '1' : null,
            fn (): string => $this->settingValue('global', 'sysui_demo_allowed'),
            function (string $allowed) use ($fixed): void {
                if ($fixed) {
                    $this->enterDemoMode();

                    return;
                }

                $this->shellScript([
                    self::DEMO.' exit',
                    $allowed === 'null'
                        ? 'settings delete global sysui_demo_allowed'
                        : 'settings put global sysui_demo_allowed '.escapeshellarg($allowed),
                ]);
            },
        ));
    }

    /**
     * Demo mode adds to the icons already shown, so it starts from a clean bar each time.
     * After a night mode or font scale change, System UI rebuilds the status bar: that
     * drops demo mode, and demo commands sent while it rebuilds showed the Wi-Fi icon twice
     * on android-36. One second after the change was enough there.
     */
    private function enterDemoMode(): void
    {
        if ($this->redrawnAt !== null) {
            $wait = $this->redrawnAt + self::$redrawSeconds - microtime(true);

            if ($wait > 0) {
                usleep((int) round($wait * 1_000_000));
            }
        }

        $this->shellScript([
            'settings put global sysui_demo_allowed 1',
            self::DEMO.' exit',
            self::DEMO.' enter',
            self::DEMO.' clock -e hhmm 0941',
            self::DEMO.' battery -e level 100 -e plugged false',
            self::DEMO.' network -e wifi show -e level 4 -e fully true',
            self::DEMO.' network -e mobile hide',
            self::DEMO.' notifications -e visible false',
        ]);
    }

    /**
     * The status bar is being rebuilt. A fixed one is fixed again once that is done.
     */
    private function redrawn(): void
    {
        $this->redrawnAt = microtime(true);

        if (array_key_exists('sysui_demo_allowed', $this->own)) {
            $this->enterDemoMode();
        }
    }

    /**
     * @param  list<string>  $commands  run in one device shell, their output dropped
     */
    private function shellScript(array $commands): void
    {
        $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', implode(' >/dev/null; ', $commands).' >/dev/null']);
    }

    /**
     * A value from `settings get`, which says `null` for one that was never set.
     */
    private function settingValue(string $namespace, string $key): string
    {
        $value = trim($this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'settings', 'get', $namespace, $key]));

        return $value === '' ? 'null' : $value;
    }

    public function background(float $seconds): void
    {
        $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'input', 'keyevent', '3']);
        usleep((int) round(max(0.0, $seconds) * 1_000_000));
        $this->launchApp();
    }

    public function relaunch(): void
    {
        $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'am', 'force-stop', $this->configuration->bundleId()]);
        $this->launchApp();
    }

    /**
     * Starts the app as its launcher icon does, which brings a running one back as it was.
     * monkey exits before it starts anything on an image with no hardware keys (android-36),
     * and the launcher intent alone does not resolve, so this names the launcher activity.
     */
    private function launchApp(): void
    {
        $bundle = $this->configuration->bundleId();
        $output = $this->command->run($this->adb(), [
            '-s', $this->serial(), 'shell',
            'am start -f 0x10200000 -n "$(cmd package resolve-activity --brief -c android.intent.category.LAUNCHER '.escapeshellarg($bundle).' | tail -n 1)"',
        ]);

        if (str_contains($output, 'Error')) {
            throw new SimulatorException("Could not start [{$bundle}]. ".trim($output));
        }
    }

    /**
     * @param  array<string, string>  $booted
     */
    private function bootNamed(array $booted): void
    {
        if (isset($booted[$this->device->name])) {
            $this->serial = $booted[$this->device->name];

            return;
        }

        if (! BootPlan::shouldBoot($this->device->named, $this->device->name, array_keys($booted)) && $booted !== []) {
            $this->serial = array_values($booted)[0];

            return;
        }

        $log = $this->configuration->appDirectory().'/emulator.log';
        $this->launch($log, EmulatorBoot::arguments($this->device->name), null);
    }

    private function shouldWipe(): bool
    {
        return Arguments::wantsWipe() && ! isset(self::$wiped[$this->device->key()]);
    }

    /**
     * @param  array<string, string>  $booted
     */
    private function wipe(array $booted): void
    {
        $serial = $booted[$this->device->name] ?? null;

        if ($serial === null && ! $this->device->named && count($booted) === 1) {
            $serial = array_values($booted)[0];
        }

        if ($serial !== null) {
            try {
                $this->command->run($this->adb(), ['-s', $serial, 'emu', 'kill']);
            } catch (SimulatorException) {
            }
        }

        $log = $this->configuration->appDirectory().'/emulator.log';
        $this->launch($log, DeviceWipe::emulator(EmulatorBoot::arguments($this->device->name)), null);
        self::$wiped[$this->device->key()] = true;
    }

    private function bootForWorker(): void
    {
        if ($this->serial !== null && self::$privateDevice === $this->device->key() && ! $this->shouldWipe()) {
            return;
        }

        if (self::$privateSerial !== null) {
            try {
                $this->command->run($this->adb(), ['-s', self::$privateSerial, 'emu', 'kill']);
            } catch (SimulatorException) {
            }

            self::$privateSerial = null;
            self::$privateDevice = null;
        }

        $port = Worker::emulatorPort();
        $log = $this->configuration->appDirectory().'/emulator-'.Worker::index().'.log';
        $arguments = EmulatorBoot::arguments($this->device->name, $port, SnapshotWriter::claim($this->device->name));

        if ($this->shouldWipe()) {
            try {
                $this->command->run($this->adb(), ['-s', 'emulator-'.$port, 'emu', 'kill']);
            } catch (SimulatorException) {
            }

            $arguments = DeviceWipe::emulator($arguments);
        }

        $this->launch($log, $arguments, 'emulator-'.$port);

        if (Arguments::wantsWipe()) {
            self::$wiped[$this->device->key()] = true;
        }
        self::$privateSerial = $this->serial;
        self::$privateDevice = $this->device->key();
    }

    /**
     * @param  list<string>  $arguments
     */
    private function launch(string $log, array $arguments, ?string $serial): void
    {
        $pid = $this->command->start($this->emulator(), $arguments, $log);
        $this->emulatorPid = $pid;
        $command = $this->command;
        Shutdown::defer(function () use ($command, $pid): void {
            $this->quitEmulator($command, $pid);
        });
        fwrite(STDERR, "Waiting for the Android Emulator [{$this->device->name}] to boot.\n");
        $this->waitUntilBooted($log, $serial);
    }

    private function waitUntilBooted(string $log, ?string $serial, int $timeoutSeconds = EmulatorBoot::TIMEOUT_SECONDS): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($this->emulatorPid !== null && ! $this->command->running($this->emulatorPid)) {
                break;
            }

            $match = $this->matchingSerial($serial);

            if ($match !== null && $this->bootCompleted($match)) {
                $this->adopt($match);

                return;
            }

            usleep(1_000_000);
        }

        throw new SimulatorException("The Android Emulator [{$this->device->name}] did not boot. See {$log}.".$this->logTail($log));
    }

    private function matchingSerial(?string $serial): ?string
    {
        if ($serial !== null) {
            $attached = AvdList::booted($this->command->run($this->adb(), ['devices']));

            return in_array($serial, $attached, true) ? $serial : null;
        }

        $booted = $this->bootedAvds();

        if (isset($booted[$this->device->name])) {
            return $booted[$this->device->name];
        }

        if (! $this->device->named && $booted !== []) {
            return array_values($booted)[0];
        }

        return null;
    }

    private function bootCompleted(string $serial): bool
    {
        if ($this->property($serial, 'sys.boot_completed') !== '1') {
            return false;
        }

        // EmulatorBoot passes -no-boot-anim, and then the bootanim service never starts,
        // so its property is never set.
        if (! in_array($this->property($serial, 'init.svc.bootanim'), ['', 'stopped'], true)) {
            return false;
        }

        try {
            $this->command->run($this->adb(), ['-s', $serial, 'shell', 'pm', 'path', 'android']);
        } catch (SimulatorException) {
            return false;
        }

        return true;
    }

    private function property(string $serial, string $name): string
    {
        try {
            return trim($this->command->run($this->adb(), ['-s', $serial, 'shell', 'getprop', $name]));
        } catch (SimulatorException) {
            return '';
        }
    }

    /**
     * @return array<string, string>
     */
    private function bootedAvds(): array
    {
        $serials = AvdList::booted($this->command->run($this->adb(), ['devices']));
        $named = [];

        foreach ($serials as $serial) {
            try {
                $name = trim($this->command->run($this->adb(), ['-s', $serial, 'emu', 'avd', 'name']));
            } catch (SimulatorException) {
                continue;
            }

            $name = preg_replace('/\s*OK\s*$/', '', $name) ?? $name;
            $named[trim($name)] = $serial;
        }

        return $named;
    }

    private function buildOnce(): void
    {
        $key = $this->device->key();

        if (isset(self::$built[$key])) {
            return;
        }

        $bundle = $this->configuration->appId();

        if ($bundle !== null && ! Arguments::wantsRebuild() && $this->debugInstalled($bundle)) {
            self::$built[$key] = true;

            return;
        }

        $arguments = ['artisan', 'native:run', 'android'];

        if ($this->serial !== null) {
            $arguments[] = $this->serial;
        }

        array_push($arguments, '--build=debug', '--no-tty');

        try {
            $this->command->run('php', $arguments, $this->configuration->appDirectory());
        } catch (SimulatorException $exception) {
            // NativePHP kills ./gradlew at 600s. On a cold CI runner that
            // lands in the middle of the native compile, and the next
            // assembleDebug finishes from the outputs already on disk.
            if (! str_contains($exception->getMessage(), 'exceeded the timeout')) {
                throw $exception;
            }

            $this->command->run('php', $arguments, $this->configuration->appDirectory());
        }

        self::$built[$key] = true;
    }

    private function debugInstalled(string $bundle): bool
    {
        $adb = $this->adb();
        $serial = $this->serial();

        try {
            $path = $this->command->run($adb, ['-s', $serial, 'shell', 'pm', 'path', $bundle]);
        } catch (SimulatorException) {
            return false;
        }

        if (! str_contains($path, 'package:')) {
            return false;
        }

        try {
            $dump = $this->command->run($adb, ['-s', $serial, 'shell', 'dumpsys', 'package', $bundle]);
        } catch (SimulatorException) {
            return false;
        }

        return preg_match('/\bDEBUGGABLE\b/', $dump) === 1;
    }

    private function xmlToJson(string $dump): string
    {
        $start = strpos($dump, '<?xml');

        if ($start === false) {
            $start = strpos($dump, '<hierarchy');
        }

        if ($start === false) {
            return '[]';
        }

        $xml = substr($dump, $start);
        $end = strpos($xml, '</hierarchy>');

        if ($end !== false) {
            $xml = substr($xml, 0, $end + strlen('</hierarchy>'));
        }

        $document = new \DOMDocument;
        if (@$document->loadXML($xml) === false) {
            return '[]';
        }

        $nodes = [];
        $root = $document->documentElement;

        if ($root instanceof \DOMElement) {
            $this->collectNodes($root, null, $nodes);
        }

        return json_encode($nodes) ?: '[]';
    }

    /**
     * @param  list<array<string, string>>  $nodes
     */
    private function collectNodes(\DOMElement $parent, ?string $inherited, array &$nodes): void
    {
        foreach ($parent->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            if ($child->tagName !== 'node') {
                $this->collectNodes($child, $inherited, $nodes);

                continue;
            }

            $node = [
                'text' => $child->getAttribute('text'),
                'content-desc' => $child->getAttribute('content-desc'),
                'resource-id' => $child->getAttribute('resource-id'),
                'class' => $child->getAttribute('class'),
                'package' => $child->getAttribute('package'),
                'bounds' => $child->getAttribute('bounds'),
                'checked' => $child->getAttribute('checked'),
                'checkable' => $child->getAttribute('checkable'),
                'enabled' => $child->getAttribute('enabled'),
                'selected' => $child->getAttribute('selected'),
                'password' => $child->getAttribute('password'),
                'scrollable' => $child->getAttribute('scrollable'),
                'scroll-axis' => $child->getAttribute('scroll-axis'),
            ];

            if ($inherited !== null) {
                $node['__inherited'] = $inherited;
            }

            $nodes[] = $node;
            $this->collectNodes($child, AccessibilityTree::inheritedChrome($node, $inherited), $nodes);
        }
    }

    /**
     * @return list<Element>
     */
    private function elementsFrom(string $dump, ?string $treePath = null): array
    {
        $json = $this->xmlToJson($dump);

        if ($treePath !== null) {
            file_put_contents($treePath, $this->publicTree($json));
        }

        $this->rememberViewport($dump, $json);

        return AccessibilityTree::summarize($json);
    }

    private function publicTree(string $json): string
    {
        $parsed = json_decode($json, true);

        if (! is_array($parsed)) {
            return $json;
        }

        $parsed = array_map(function (mixed $node): mixed {
            if (is_array($node)) {
                unset($node['__inherited']);
            }

            return $node;
        }, $parsed);

        return json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: $json;
    }

    private function rememberViewport(string $dump, string $json): void
    {
        if (preg_match('/<hierarchy\b([^>]*)>/', $dump, $matches) === 1) {
            $width = [];
            $height = [];

            if (preg_match('/\bwidth="([\d.]+)"/', $matches[1], $width) === 1 && preg_match('/\bheight="([\d.]+)"/', $matches[1], $height) === 1) {
                $this->viewport = [(float) $width[1], (float) $height[1]];

                return;
            }
        }

        [$width, $height] = AccessibilityTree::viewport($json);

        if ($width > 0 && $height > 0) {
            $this->viewport = [$width, $height];
        }
    }

    private function adopt(string $serial): void
    {
        $this->serial = $serial;
    }

    /**
     * `adb emu kill` asks the emulator to write its Quick Boot snapshot and
     * exit. SIGTERM before that write finishes leaves the next boot with no
     * installed debug build.
     */
    private function quitEmulator(Command $command, int $pid): void
    {
        if ($this->serial !== null) {
            try {
                $command->run($this->adb(), ['-s', $this->serial, 'emu', 'kill']);
            } catch (SimulatorException) {
            }

            if ($command->wait($pid, 60)) {
                return;
            }
        }

        if ($command->running($pid)) {
            $command->stop($pid);
        }
    }

    private function logcat(string $bundle): ?string
    {
        $parts = preg_split('/\s+/', trim($this->read(['shell', 'pidof', $bundle]) ?? '')) ?: [];
        $pid = $parts[0] ?? '';

        if ($pid !== '' && ctype_digit($pid)) {
            return $this->read(['logcat', '-d', '-t', '400', '--pid='.$pid]);
        }

        $dump = $this->read(['logcat', '-d', '-t', '400']);

        if ($dump === null || $dump === '') {
            return null;
        }

        $lines = array_values(array_filter(
            preg_split('/\r\n|\n|\r/', $dump) ?: [],
            fn (string $line): bool => str_contains($line, $bundle),
        ));

        if ($lines === []) {
            return null;
        }

        return implode("\n", array_slice($lines, -200))."\n";
    }

    /**
     * @param  list<string>  $arguments
     */
    private function read(array $arguments): ?string
    {
        try {
            return $this->command->run($this->adb(), array_merge(['-s', $this->serial()], $arguments));
        } catch (SimulatorException) {
            return null;
        }
    }

    private function keep(?string $contents, string $path): bool
    {
        if ($contents === null || $contents === '') {
            return false;
        }

        return file_put_contents($path, $contents) !== false;
    }

    private function stopScreenRecord(): void
    {
        try {
            $pids = preg_split('/\s+/', trim($this->command->run($this->adb(), [
                '-s', $this->serial(), 'shell', 'pidof', 'screenrecord',
            ]))) ?: [];
        } catch (SimulatorException) {
            return;
        }

        $pids = array_values(array_filter($pids, fn (string $pid): bool => $pid !== ''));

        if ($pids === []) {
            return;
        }

        // The on-device process finalizes the mp4 on SIGINT. Stopping adb does not.
        try {
            $this->command->run($this->adb(), array_merge(
                ['-s', $this->serial(), 'shell', 'kill', '-INT'],
                $pids,
            ));
        } catch (SimulatorException) {
        }
    }

    private function serial(): string
    {
        return $this->serial ?? throw new SimulatorException('No Android Emulator is selected.');
    }

    private function adb(): string
    {
        return AndroidSdk::binary('platform-tools/adb', 'adb');
    }

    private function logTail(string $log): string
    {
        if (! is_file($log)) {
            return '';
        }

        $lines = file($log, FILE_IGNORE_NEW_LINES);

        if (! is_array($lines) || $lines === []) {
            return '';
        }

        return "\n".implode("\n", array_slice($lines, -15));
    }

    private function emulator(): string
    {
        return AndroidSdk::binary('emulator/emulator', 'emulator');
    }
}
