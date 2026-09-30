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

    private ?string $udid = null;

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
            $this->command->run('xcrun', ['simctl', 'shutdown', SimulatorList::udidFor($json, $name)]);
        }

        if (! $this->device->named && count($bootedNames) === 1) {
            $this->udid = $booted[0]['udid'];

            if ($restartForKeyboard) {
                $this->command->run('xcrun', ['simctl', 'shutdown', $this->udid]);
                $this->command->run('xcrun', ['simctl', 'boot', $this->udid]);
                $this->command->run('xcrun', ['simctl', 'bootstatus', $this->udid, '-b']);
            }
        } else {
            $this->udid = SimulatorList::udidFor($json, $this->device->name);
            $alreadyBooted = in_array($this->device->name, $bootedNames, true);

            if (BootPlan::shouldBoot($this->device->named, $this->device->name, $bootedNames) || ($restartForKeyboard && $alreadyBooted)) {
                if ($alreadyBooted) {
                    $this->command->run('xcrun', ['simctl', 'shutdown', $this->udid]);
                }

                $this->command->run('xcrun', ['simctl', 'boot', $this->udid]);
                $this->command->run('xcrun', ['simctl', 'bootstatus', $this->udid, '-b']);
                $udid = $this->udid;
                $command = $this->command;
                Shutdown::defer(function () use ($command, $udid): void {
                    try {
                        $command->run('xcrun', ['simctl', 'shutdown', $udid]);
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
        $this->connectHardwareKeyboard();
        $json = $this->command->run('xcrun', ['simctl', 'list', 'devices', 'available', '-j']);
        $source = SimulatorList::udidFor($json, $this->device->name);
        $this->udid = trim($this->command->run('xcrun', ['simctl', 'clone', $source, $this->device->name.' '.Worker::nameSuffix()]));
        self::$workerSimulator = $this->udid;
        $this->command->run('xcrun', ['simctl', 'boot', $this->udid]);
        $this->command->run('xcrun', ['simctl', 'bootstatus', $this->udid, '-b']);
        $simulator = $this->udid;
        $command = $this->command;
        Shutdown::defer(function () use ($command, $simulator): void {
            self::forgetSimulator($command, $simulator);
        });
    }

    private function releaseWorkerSimulator(): void
    {
        if (self::$workerSimulator === null) {
            return;
        }

        $simulator = self::$workerSimulator;
        self::$workerSimulator = null;
        self::forgetSimulator($this->command, $simulator);
    }

    private static function forgetSimulator(Command $command, string $simulator): void
    {
        try {
            $command->run('xcrun', ['simctl', 'shutdown', $simulator]);
        } catch (SimulatorException) {
        }

        try {
            $command->run('xcrun', ['simctl', 'delete', $simulator]);
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

        $container = rtrim(trim($this->command->run('xcrun', ['simctl', 'get_app_container', $this->udid(), $bundle, 'data'])), '/');

        if ($container === '') {
            throw new SimulatorException("No data container for [{$bundle}].");
        }

        $destination = $container.'/Library/Application Support/database/database.sqlite';
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

    public function tap(float $x, float $y): void
    {
        $this->client()->stream('hid', Hid::tap($x, $y));
    }

    public function swipe(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->client()->stream('hid', Hid::swipe($x1, $y1, $x2, $y2));
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

            $this->client()->stream('hid', Hid::text($piece));
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
     * boot again.
     */
    private function connectHardwareKeyboard(): bool
    {
        if (self::$hardwareKeyboard) {
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
            $container = rtrim(trim($this->command->run('xcrun', [
                'simctl', 'get_app_container', $this->udid(), $this->configuration->bundleId(), 'data',
            ])), '/');
        } catch (SimulatorException) {
            return [];
        }

        if ($container === '') {
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
        ], $this->configuration->appDirectory());

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
