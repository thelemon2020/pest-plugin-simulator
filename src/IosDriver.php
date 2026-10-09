<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Grpc\Client;
use NativePhp\Simulator\Grpc\Protobuf;

final class IosDriver implements Driver
{
    private static array $built = [];

    private static ?int $companionPid = null;

    private static ?string $companionSimulator = null;

    private static ?string $workerSimulator = null;

    private static bool $hardwareKeyboard = false;

    /** @var array<string, true> */
    private static array $wiped = [];

    private ?string $udid = null;

    private ?string $dataContainer = null;

    private ?int $recordingPid = null;

    private ?Client $client = null;

    /** @var array{0: float, 1: float} */
    private array $viewport = [390.0, 844.0];

    public function __construct(
        private readonly Device $device,
        private readonly Configuration $configuration,
        private readonly Command $command = new Command,
        private readonly Socket $socket = new Socket,
    ) {}

    public function ensureReady(): void
    {
        if (Worker::parallel()) {
            $this->bootForWorker();
        } else {
            $this->bootShared();
        }

        $this->startCompanion();
        $this->buildOnce();
    }

    private function bootShared(): void
    {
        $restartForKeyboard = $this->connectHardwareKeyboard();
        $json = $this->command->run('xcrun', ['simctl', 'list', 'devices', 'available', '-j']);
        $booted = SimulatorList::booted($json);
        $bootedNames = array_column($booted, 'name');

        foreach (BootPlan::shutdowns($this->device->named, $this->device->name, $bootedNames) as $name) {
            $this->command->run('xcrun', ['simctl', 'shutdown', SimulatorList::udidFor($json, $name)], timeout: Command::DEVICE_TIMEOUT);
        }

        if (! $this->device->named && count($bootedNames) === 1) {
            $this->udid = $booted[0]['udid'];
            $wiped = $this->wipeSimulator($this->udid);

            if ($wiped || $restartForKeyboard) {
                if (! $wiped) {
                    $this->command->run('xcrun', ['simctl', 'shutdown', $this->udid], timeout: Command::DEVICE_TIMEOUT);
                }

                $this->command->run('xcrun', ['simctl', 'boot', $this->udid], timeout: Command::DEVICE_TIMEOUT);
                $this->command->run('xcrun', ['simctl', 'bootstatus', $this->udid, '-b'], timeout: Command::DEVICE_TIMEOUT);
            }
        } else {
            $this->udid = SimulatorList::udidFor($json, $this->device->name);
            $alreadyBooted = in_array($this->device->name, $bootedNames, true);
            $wiped = $this->wipeSimulator($this->udid);

            if ($wiped) {
                $alreadyBooted = false;
            }

            if ($wiped || BootPlan::shouldBoot($this->device->named, $this->device->name, $bootedNames) || ($restartForKeyboard && $alreadyBooted)) {
                if ($alreadyBooted) {
                    $this->command->run('xcrun', ['simctl', 'shutdown', $this->udid], timeout: Command::DEVICE_TIMEOUT);
                }

                $this->command->run('xcrun', ['simctl', 'boot', $this->udid], timeout: Command::DEVICE_TIMEOUT);
                $this->command->run('xcrun', ['simctl', 'bootstatus', $this->udid, '-b'], timeout: Command::DEVICE_TIMEOUT);
                $udid = $this->udid;
                $command = $this->command;
                Shutdown::defer(function () use ($command, $udid): void {
                    try {
                        $command->run('xcrun', ['simctl', 'shutdown', $udid], timeout: Command::DEVICE_TIMEOUT);
                    } catch (SimulatorException) {
                    }
                });
            }
        }
    }

    private function bootForWorker(): void
    {
        if ($this->udid !== null && self::$workerSimulator === $this->udid) {
            return;
        }

        $this->releaseWorkerSimulator();
        $restartForKeyboard = $this->connectHardwareKeyboard();
        $json = $this->command->run('xcrun', ['simctl', 'list', 'devices', 'available', '-j']);
        $name = $this->device->name.' '.Worker::nameSuffix();
        $source = SimulatorList::named($json, $this->device->name);

        if ($source === null) {
            throw new SimulatorException("No Simulator named [{$this->device->name}] is installed.");
        }

        $existing = SimulatorList::named($json, $name, $source['runtime']);

        if (Arguments::wantsWipe() && ! isset(self::$wiped[$name])) {
            if ($existing !== null) {
                DeviceWipe::deleteSimulator($this->command, $existing['udid']);
                $existing = null;
            }

            self::$wiped[$name] = true;
        }

        foreach (SimulatorList::devices($json) as $device) {
            if ($device['name'] !== $name || $device['runtime'] === $source['runtime']) {
                continue;
            }

            DeviceWipe::deleteSimulator($this->command, $device['udid']);
        }

        if ($existing === null) {
            $this->udid = trim($this->command->run('xcrun', ['simctl', 'clone', $source['udid'], $name], timeout: Command::DEVICE_TIMEOUT));
            $state = 'Shutdown';
        } else {
            $this->udid = $existing['udid'];
            $state = $existing['state'];
        }

        self::$workerSimulator = $this->udid;
        $booted = $state === 'Booted';

        if ($booted && $restartForKeyboard) {
            $this->command->run('xcrun', ['simctl', 'shutdown', $this->udid], timeout: Command::DEVICE_TIMEOUT);
            $booted = false;
        }

        if (! $booted) {
            $this->command->run('xcrun', ['simctl', 'boot', $this->udid], timeout: Command::DEVICE_TIMEOUT);
        }

        // A leftover whose state is already Booted can still be mid-boot.
        $this->command->run('xcrun', ['simctl', 'bootstatus', $this->udid, '-b'], timeout: Command::DEVICE_TIMEOUT);

        $simulator = $this->udid;
        $command = $this->command;
        Shutdown::defer(function () use ($command, $simulator): void {
            self::shutdownSimulator($command, $simulator);
        });
    }

    private function releaseWorkerSimulator(): void
    {
        if (self::$workerSimulator === null) {
            return;
        }

        $simulator = self::$workerSimulator;
        self::$workerSimulator = null;
        self::shutdownSimulator($this->command, $simulator);
    }

    private function wipeSimulator(string $udid): bool
    {
        if (! Arguments::wantsWipe() || isset(self::$wiped[$udid])) {
            return false;
        }

        DeviceWipe::eraseSimulator($this->command, $udid);
        self::$wiped[$udid] = true;

        return true;
    }

    private static function shutdownSimulator(Command $command, string $simulator): void
    {
        try {
            $command->run('xcrun', ['simctl', 'shutdown', $simulator], timeout: Command::DEVICE_TIMEOUT);
        } catch (SimulatorException) {
        }
    }

    public function open(string $url): void
    {
        $this->command->run('xcrun', ['simctl', 'openurl', $this->udid(), $url]);
    }

    public function installDatabase(string $sqlitePath): void
    {
        $bundle = $this->configuration->bundleId();

        try {
            $this->command->run('xcrun', ['simctl', 'terminate', $this->udid(), $bundle]);
        } catch (SimulatorException $exception) {
            // The app was never running, which is the ordinary case on a first install — not
            // a failure to install over it. simctl's wording for that isn't stable across
            // versions: older Xcodes say "No such process", newer ones say "found nothing to
            // terminate". Without both, a run on whichever version says the one this wasn't
            // written for throws on every single test.
            $message = strtolower($exception->getMessage());

            if (! str_contains($message, 'no such process') && ! str_contains($message, 'nothing to terminate')) {
                throw $exception;
            }
        }

        $destination = $this->dataContainer().'/Library/Application Support/database/database.sqlite';
        $directory = dirname($destination);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new SimulatorException("Could not create [{$directory}].");
        }

        if (! copy($sqlitePath, $destination)) {
            throw new SimulatorException("Could not copy the test database into [{$destination}].");
        }

        foreach ([$destination.'-wal', $destination.'-shm'] as $sidecar) {
            if (is_file($sidecar) && ! unlink($sidecar)) {
                throw new SimulatorException("Could not remove [{$sidecar}].");
            }
        }
    }

    public function describe(?string $treePath = null): array
    {
        $json = $this->accessibilityJson($treePath);

        [$width, $height] = AccessibilityTree::viewport($json);

        if ($width > 0 && $height > 0) {
            $this->viewport = [$width, $height];
        }

        return AccessibilityTree::summarize($json);
    }

    private function accessibilityJson(?string $treePath = null): string
    {
        $payload = $this->client()->unary('accessibility_info', Hid::accessibilityInfo());
        $json = Protobuf::stringField($payload, 1);

        if ($treePath !== null) {
            file_put_contents($treePath, $json);
        }

        return $json;
    }

    /**
     * A real finger holds a touch down for a beat before lifting it; a bare down+up with no
     * gap between them is how long a purely synthetic tap takes instead, and for some
     * controls that gap is load-bearing.
     *
     * First tried as two separate `stream()` calls (each its own HTTP/2 connection) with a
     * `usleep()` between them. That fixed a plain `<native:toggle>` — not inside any
     * scroll-view or sheet — at 150ms (80ms did not), which never flipped under an
     * instantaneous tap. It did NOT fix a `<native:chip>` inside a horizontal
     * `<native:scroll-view>`, tried up to 300ms: plausibly because two separate connections
     * never read as one continuous touch session to begin with, so no amount of delay
     * between them could matter.
     *
     * `streamPaced()` (see Client.php) fixes that: one connection, written to incrementally
     * with a real gap mid-body, so whatever continuity a scroll view's gesture arbitration
     * needs survives. At 400ms this fixed the chip AND, as a bonus nobody was chasing,
     * fixed two previously-unrelated mysteries in the same investigation: a plain "Cancel"
     * button and a multiline field, both inside a `<native:bottom-sheet>`, which a 500ms
     * hold under the OLD two-connection method never touched. A plain `<native:toggle>`
     * (LightingSchedule's "Run this schedule" switch), also previously fixed by the OLD
     * method at 150ms, keeps working under this one too.
     *
     * Important caveat, found AFTER first declaring the above "fixed": all three — chip,
     * sheet, switch — are reliable on an otherwise-idle machine but measurably flaky under
     * heavy host contention (tested at a sustained load average of 60-100 on a 10-core Mac,
     * driven by an unrelated video call + remote-desktop session). 200ms was tried as a
     * possible fix (on the theory a shorter hold avoids iOS's long-press/haptic-touch
     * gesture arbitration window) and made things WORSE, not better, so this isn't a
     * duration problem — it's host scheduling jitter affecting the real wall-clock gap
     * `streamPaced()` depends on. No in-plugin fix for that is known; treat it as the same
     * class of risk as the existing TimezonePicker keyboard flake, not a regression.
     *
     * One case is NOT explained by load: a generic pressable (`SegmentEditor`'s rename
     * disclosure) on a screen with its own complex custom-drawn gesture surface (the dynamic
     * shelf board — lights, dividers, drag-to-place) failed 150ms through 1.5s under both
     * methods, and failed 3/3 again under streamPaced at 400ms in the SAME heavily-loaded
     * conditions where chip/sheet/switch were each passing some fraction of their runs —
     * i.e. this one is deterministic, not just another casualty of the host contention
     * above. Current best guess is that surface's own gesture recognizer is claiming the
     * touch before the disclosure's tap gesture ever sees it. Genuinely unresolved; no
     * write test exists for it in SegmentEditorTest.
     */
    private const TAP_HOLD_MICROSECONDS = 400_000;

    public function tap(float $x, float $y): void
    {
        $this->client()->streamPaced('hid', Hid::tap($x, $y), self::TAP_HOLD_MICROSECONDS);
    }

    public function press(float $x, float $y, float $seconds = 0.8): void
    {
        $this->client()->streamPaced('hid', Hid::tap($x, $y), (int) round($seconds * 1_000_000));
    }

    public function swipe(float $x1, float $y1, float $x2, float $y2, float $seconds = 0.3): void
    {
        $this->client()->stream('hid', Hid::swipe($x1, $y1, $x2, $y2, $seconds));
    }

    public function back(): void
    {
        [$width, $height] = $this->viewport();
        [$x1, $y1, $x2, $y2] = Gesture::back($width, $height);
        // A flick from the bezel is read as a scroll. Back needs a slow drag.
        $this->client()->stream('hid', Hid::swipe($x1, $y1, $x2, $y2, 0.6));
    }

    public function clear(int $characters = 40): void
    {
        $this->client()->stream('hid', Hid::selectAll());
        $this->client()->stream('hid', Hid::backspace());
    }

    /**
     * `Hid::text()` turns a multi-character piece into many key down/up events (more per
     * character once Shift is involved), and `stream()` writes all of them onto the wire
     * back-to-back with no gap at all — curl hands the whole body to one write(). That is
     * faster than the Simulator's own keyboard can keep up with: confirmed on real
     * hardware that typing "Talk Talk" into a plain `<native:text-field>` silently landed
     * as "Talk", "Spirit Of Eden" as "Spirit", and "Tokyo" (no space at all, so not a
     * word-boundary thing) as "To" — always a clean prefix, never reordered or corrupted,
     * which is the signature of an input queue dropping whatever arrives once it's full
     * rather than any character-level corruption. Reproduced identically under heavy host
     * load, under an idle host, and on a freshly `--wipe`d Simulator, so it isn't the
     * contention class of flake documented on `tap()` above — the burst itself is too fast
     * regardless of the host.
     *
     * `streamPaced()` already exists for exactly this shape of problem (see Client.php) and
     * takes an arbitrary message list, not just a tap's down+up pair — so the same named-pipe
     * mechanism that gives a tap's two events real wall-clock continuity also works here,
     * putting a real gap between every key event `Hid::text()` produces. 20ms is far below
     * `TAP_HOLD_MICROSECONDS` (that one is about iOS gesture arbitration, a different
     * mechanism) — just enough for the keyboard's own event queue to keep up without making
     * a sentence take seconds to type.
     *
     * This fully fixed a single-field case end to end (a plain `native:model` search field,
     * one `type()` call, nothing typed before or after it) — confirmed going from reliably
     * failing to reliably passing on real hardware. It only partially helped a harder case:
     * two `native:model` fields typed back to back with no settle between them (a
     * `type('Artist', 'Talk Talk')` immediately followed by `type('Title', 'Spirit Of
     * Eden')`), where both fields still truncate, just less. Tried 60ms on that same case
     * expecting it to help further and it did the opposite — WORSE truncation ("Ta", "Spiri"
     * — short of even 20ms's result), which rules out "not enough gap yet" as the whole
     * story: a longer per-key gap makes each `type()` call take proportionally longer in
     * wall-clock time, and something tied to elapsed time (not event count) seems to start
     * working against it past a point. Only one run was tried at each value, so treat the
     * 60ms result as a signal to stop turning this dial blindly, not as a proven curve.
     *
     * Current best guess for the back-to-back case: it's a second, different bug layered on
     * top of the one this fixes — `native:model` round-trips the field's value to the server
     * on every change and re-applies whatever comes back, and the project's own memory
     * already documents this exact mechanism losing fast typing when a round trip lands
     * late (originally found with `#[Poll]`, but a plain per-keystroke `native:model`
     * round-trip is the same race without needing a poll at all). Consistent with what's
     * typed into the FIRST field (no poll, no prior round trip in flight) still truncating
     * at a fixed point regardless of this gap. Not chased further here — would need either
     * reproducing it against a field with no `native:model` round-trip at all, or adding a
     * settle between `type()` calls, to tell apart from a residual pacing issue.
     */
    private const TEXT_KEY_GAP_MICROSECONDS = 20_000;

    public function text(string $text): void
    {
        if ($text === '') {
            return;
        }

        foreach (IosText::pieces($text) as $piece) {
            if (IosText::paste($piece)) {
                $this->insertSymbol($piece);

                continue;
            }

            $this->client()->streamPaced('hid', Hid::text($piece), self::TEXT_KEY_GAP_MICROSECONDS);
        }
    }

    /**
     * The software keyboard does not turn Shift-2 into @, whichever field
     * is focused, and a tap on a key reported below the display hits the
     * home indicator and dismisses it. A key that is actually on the glass
     * is tapped. Otherwise the hardware keyboard, connected before boot,
     * types the character.
     */
    private function insertSymbol(string $character): void
    {
        $json = $this->accessibilityJson();
        [$width, $height] = AccessibilityTree::viewport($json);

        if ($width > 0 && $height > 0) {
            $this->viewport = [$width, $height];
        }

        $point = AccessibilityTree::keyPoint($json, $character, $this->viewport[0], $this->viewport[1]);

        if ($point !== null) {
            $this->tap($point[0], $point[1]);

            return;
        }

        try {
            $this->client()->stream('hid', Hid::text($character));

            return;
        } catch (SimulatorException) {
        }

        $this->command->input('xcrun', ['simctl', 'pbcopy', $this->udid()], $character);
        usleep(100_000);
        $this->client()->stream('hid', Hid::paste());
    }

    /**
     * The software keyboard ignores Shift-2. The preference is read when
     * the simulator launches, so a simulator that is already up has to
     * boot again. A simulator booted after the preference was turned on
     * does not.
     */
    private function connectHardwareKeyboard(): bool
    {
        if (self::$hardwareKeyboard) {
            return false;
        }

        try {
            $current = trim($this->command->run('defaults', ['read', 'com.apple.iphonesimulator', 'ConnectHardwareKeyboard']));
        } catch (SimulatorException) {
            $current = '';
        }

        if (in_array($current, ['1', 'YES', 'true'], true)) {
            self::$hardwareKeyboard = true;

            return false;
        }

        try {
            $this->command->run('defaults', ['write', 'com.apple.iphonesimulator', 'ConnectHardwareKeyboard', '-bool', 'YES']);
        } catch (SimulatorException) {
            return false;
        }

        self::$hardwareKeyboard = true;

        return true;
    }

    private function dataContainer(): string
    {
        if ($this->dataContainer !== null) {
            return $this->dataContainer;
        }

        $bundle = $this->configuration->bundleId();
        $container = rtrim(trim($this->command->run('xcrun', [
            'simctl', 'get_app_container', $this->udid(), $bundle, 'data',
        ])), '/');

        if ($container === '') {
            throw new SimulatorException("No data container for [{$bundle}].");
        }

        return $this->dataContainer = $container;
    }

    public function viewport(): array
    {
        return $this->viewport;
    }

    public function screenshot(string $path): void
    {
        $this->command->run('xcrun', ['simctl', 'io', $this->udid(), 'screenshot', $path]);
    }

    public function startRecording(string $path): void
    {
        $this->recordingPid = $this->command->start('xcrun', [
            'simctl', 'io', $this->udid(), 'recordVideo', '--codec=h264', '--force', $path,
        ], $this->recordingLog());
    }

    public function stopRecording(): void
    {
        if ($this->recordingPid === null) {
            return;
        }

        $pid = $this->recordingPid;
        $this->recordingPid = null;
        // simctl writes the movie when this process receives SIGINT.
        $this->command->interrupt($pid);
    }

    public function grant(array $services): void
    {
        $this->privacy('grant', $services);
    }

    public function revoke(array $services): void
    {
        $this->privacy('revoke', $services);
    }

    /**
     * @param  list<string>  $services
     */
    private function privacy(string $action, array $services): void
    {
        $bundle = $this->configuration->bundleId();

        foreach (Permissions::targets('ios', $services) as $service) {
            Permissions::attempt(fn () => $this->command->run('xcrun', [
                'simctl', 'privacy', $this->udid(), $action, $service, $bundle,
            ]));
        }
    }

    public function captureLogs(string $directory): array
    {
        try {
            $container = $this->dataContainer();
        } catch (SimulatorException) {
            return [];
        }

        $source = $container.'/Library/Application Support/storage/logs/laravel.log';

        if (! is_file($source) || ! copy($source, $directory.'/laravel.log')) {
            return [];
        }

        return [$directory.'/laravel.log'];
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

        $this->command->run('php', [
            'artisan', 'native:run', 'ios', $this->udid(), '--build=debug', '--no-tty',
        ], $this->configuration->appDirectory(), Command::BUILD_TIMEOUT);

        self::$built[$key] = true;
    }

    private function debugInstalled(string $bundle): bool
    {
        try {
            $container = rtrim(trim($this->command->run('xcrun', [
                'simctl', 'get_app_container', $this->udid(), $bundle, 'app',
            ])), '/');
        } catch (SimulatorException) {
            return false;
        }

        if ($container === '' || ! str_ends_with($container, '.app')) {
            return false;
        }

        try {
            $entitlements = $this->command->run('codesign', ['-d', '--entitlements', '-', $container]);
        } catch (SimulatorException) {
            return false;
        }

        return preg_match('/get-task-allow<\/key>\s*<true\s*\/>/i', $entitlements) === 1
            || preg_match('/\[Key\]\s*get-task-allow\s*\[Value\]\s*\[Bool\]\s*true/i', $entitlements) === 1;
    }

    private function startCompanion(): void
    {
        if ($this->client !== null) {
            return;
        }

        $simulator = $this->udid();
        $port = $this->companionPort($simulator);

        if (self::$companionSimulator === $simulator && $this->socket->reachable($port)) {
            $this->client = new Client('http://127.0.0.1:'.$port);

            return;
        }

        if (self::$companionPid !== null) {
            $this->command->stop(self::$companionPid);
            self::$companionPid = null;
            self::$companionSimulator = null;
        }

        if (! Worker::parallel() && $this->socket->reachable($port) && $this->listenerMatches($port, $simulator)) {
            $this->client = new Client('http://127.0.0.1:'.$port);
            self::$companionSimulator = $simulator;

            return;
        }

        $suffix = Worker::parallel() ? '-'.Worker::index() : '';
        $log = $this->configuration->appDirectory().'/companion'.$suffix.'.log';
        $pid = $this->command->start(Companion::binary(), ['--udid', $simulator, '--grpc-port', (string) $port, '--log-level', 'info'], $log);
        self::$companionPid = $pid;
        self::$companionSimulator = $simulator;
        $command = $this->command;
        Shutdown::defer(function () use ($command, $pid): void {
            $command->stop($pid);
        });

        // A cold companion on CI re-execs while it loads MobileDevice, so the
        // pid from start() is gone before the port opens. The port itself
        // took 104s. Wait that out instead of treating the old pid as a crash.
        $deadline = microtime(true) + 180;

        while (microtime(true) < $deadline) {
            if ($this->socket->reachable($port)) {
                $this->client = new Client('http://127.0.0.1:'.$port);

                return;
            }

            usleep(200_000);
        }

        throw new SimulatorException("idb_companion did not open port {$port}. See {$log}.".$this->logTail($log));
    }

    private function companionPort(string $simulator): int
    {
        $preferred = Worker::grpcPort();

        if (Worker::parallel() || $this->listenerMatches($preferred, $simulator)) {
            return $preferred;
        }

        for ($port = $preferred + 1; $port < $preferred + 20; $port++) {
            if ($this->listenerMatches($port, $simulator)) {
                return $port;
            }
        }

        throw new SimulatorException('idb_companion is already listening for another simulator, and no free port was found.');
    }

    private function listenerMatches(int $port, string $simulator): bool
    {
        if (! $this->socket->reachable($port)) {
            return true;
        }

        $attached = $this->listenerUdid($port);

        return $attached === null || $attached === $simulator;
    }

    private function listenerUdid(int $port): ?string
    {
        try {
            $listing = $this->command->run('lsof', ['-nP', '-iTCP:'.$port, '-sTCP:LISTEN', '-t']);
        } catch (SimulatorException) {
            return null;
        }

        $pid = trim(strtok($listing, "\n") ?: '');

        if ($pid === '' || ! ctype_digit($pid)) {
            return null;
        }

        try {
            $args = $this->command->run('ps', ['-o', 'args=', '-p', $pid]);
        } catch (SimulatorException) {
            return null;
        }

        if (preg_match('/--udid\s+(\S+)/', $args, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function client(): Client
    {
        return $this->client ?? throw new SimulatorException('The iOS companion is not connected.');
    }

    private function udid(): string
    {
        return $this->udid ?? throw new SimulatorException('No iOS Simulator is selected.');
    }

    private function recordingLog(): string
    {
        return sys_get_temp_dir().'/pest-simulator-record-'.Worker::index().'.log';
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
}
