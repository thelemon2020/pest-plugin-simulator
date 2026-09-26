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

        return AccessibilityTree::summarize($json);
    }

    public function tap(float $x, float $y): void
    {
        $this->client()->stream('hid', Hid::tap($x, $y));
    }

    public function text(string $text): void
    {
        $this->client()->stream('hid', Hid::text($text));
    }

    public function screenshot(string $path): void
    {
        $this->command->run('xcrun', ['simctl', 'io', $this->udid(), 'screenshot', $path]);
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

        $binary = $this->companionBinary();
        $log = $this->configuration->appDirectory().'/companion.log';
        $this->command->start($binary, ['--udid', $this->udid(), '--grpc-port', (string) $port, '--log-level', 'info'], $log);

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

    private function companionBinary(): string
    {
        $fromEnv = getenv('IDB_COMPANION');

        if (is_string($fromEnv) && $fromEnv !== '' && is_executable($fromEnv)) {
            return $fromEnv;
        }

        foreach (['/opt/homebrew/bin/idb_companion', '/usr/local/bin/idb_companion'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        throw new SimulatorException('idb_companion is not installed. Install it with `brew install idb-companion`.');
    }
}
