<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Grpc\Client;
use NativePhp\Simulator\Grpc\Protobuf;

final class IosDriver implements Driver
{
    private static array $built = [];

    private ?string $udid = null;

    private ?Client $client = null;

    /** @var array{0: float, 1: float} */
    private array $viewport = [390.0, 844.0];

    public function __construct(
        private readonly Device $device,
        private readonly Configuration $configuration,
        private readonly Command $command = new Command,
    ) {}

    public function ensureReady(): void
    {
        $json = $this->command->run('xcrun', ['simctl', 'list', 'devices', 'available', '-j']);
        $booted = SimulatorList::booted($json);
        $bootedNames = array_column($booted, 'name');

        foreach (BootPlan::shutdowns($this->device->named, $this->device->name, $bootedNames) as $name) {
            $this->command->run('xcrun', ['simctl', 'shutdown', SimulatorList::udidFor($json, $name)]);
        }

        if (! $this->device->named && count($bootedNames) === 1) {
            $this->udid = $booted[0]['udid'];
        } else {
            $this->udid = SimulatorList::udidFor($json, $this->device->name);

            if (BootPlan::shouldBoot($this->device->named, $this->device->name, $bootedNames)) {
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

        $this->startCompanion();
        $this->buildOnce();
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
            if (! str_contains(strtolower($exception->getMessage()), 'no such process')) {
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
        $payload = $this->client()->unary('accessibility_info', Hid::accessibilityInfo());
        $json = Protobuf::stringField($payload, 1);

        if ($treePath !== null) {
            file_put_contents($treePath, $json);
        }

        [$width, $height] = AccessibilityTree::viewport($json);

        if ($width > 0 && $height > 0) {
            $this->viewport = [$width, $height];
        }

        return AccessibilityTree::summarize($json);
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
        $this->swipe($x1, $y1, $x2, $y2);
    }

    public function clear(): void
    {
        $this->client()->stream('hid', Hid::selectAll());
        $this->client()->stream('hid', Hid::backspace());
    }

    public function text(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->client()->stream('hid', Hid::text($text));
    }

    public function viewport(): array
    {
        return $this->viewport;
    }

    public function screenshot(string $path): void
    {
        $this->command->run('xcrun', ['simctl', 'io', $this->udid(), 'screenshot', $path]);
    }

    public function grant(array $services): void
    {
        $bundle = $this->configuration->bundleId();

        foreach (Permissions::targets('ios', $services) as $service) {
            Permissions::attempt(fn () => $this->command->run('xcrun', [
                'simctl', 'privacy', $this->udid(), 'grant', $service, $bundle,
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

        $this->command->run('php', [
            'artisan', 'native:run', 'ios', $this->udid(), '--build=debug', '--no-tty',
        ], $this->configuration->appDirectory());

        self::$built[$key] = true;
    }

    private function startCompanion(): void
    {
        $port = 10882;
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

        if (is_resource($socket)) {
            fclose($socket);
            $this->client = new Client('http://127.0.0.1:'.$port);

            return;
        }

        $binary = Companion::binary();
        $log = $this->configuration->appDirectory().'/companion.log';
        $pid = $this->command->start($binary, ['--udid', $this->udid(), '--grpc-port', (string) $port, '--log-level', 'info'], $log);
        $command = $this->command;
        Shutdown::defer(function () use ($command, $pid): void {
            $command->stop($pid);
        });

        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

            if (is_resource($socket)) {
                fclose($socket);
                $this->client = new Client('http://127.0.0.1:'.$port);

                return;
            }

            usleep(200_000);
        }

        throw new SimulatorException("idb_companion did not open port {$port}. See {$log}.");
    }

    private function client(): Client
    {
        return $this->client ?? throw new SimulatorException('The iOS companion is not connected.');
    }

    private function udid(): string
    {
        return $this->udid ?? throw new SimulatorException('No iOS Simulator is selected.');
    }
}
