<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class AndroidDriver implements Driver
{
    private static array $built = [];

    private ?string $serial = null;

    /** @var array{0: float, 1: float} */
    private array $viewport = [390.0, 844.0];

    private bool $launchedEmulator = false;

    private bool $emulatorTracked = false;

    public function __construct(
        private readonly Device $device,
        private readonly Configuration $configuration,
        private readonly Command $command = new Command,
    ) {}

    public function ensureReady(): void
    {
        $booted = $this->bootedAvds();

        foreach (BootPlan::shutdowns($this->device->named, $this->device->name, array_keys($booted)) as $name) {
            $this->command->run($this->adb(), ['-s', $booted[$name], 'emu', 'kill']);
        }

        if (! $this->device->named && count($booted) === 1) {
            $this->serial = array_values($booted)[0];
        } else {
            $this->bootNamed($booted);
        }

        $this->buildOnce();
    }

    public function installDatabase(string $sqlitePath): void
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
            // The pushed file is owned by the shell user. The app can read it only after this.
            $this->command->run($adb, ['-s', $serial, 'shell', 'chmod', '644', $remote]);
            $this->command->run($adb, ['-s', $serial, 'shell', 'run-as', $bundle, 'mkdir', '-p', $directory]);
            $this->command->run($adb, ['-s', $serial, 'shell', 'run-as', $bundle, 'cp', $remote, $database]);
            $this->command->run($adb, ['-s', $serial, 'shell', 'run-as', $bundle, 'rm', '-f', $database.'-wal', $database.'-shm']);
        } finally {
            try {
                $this->command->run($adb, ['-s', $serial, 'shell', 'rm', '-f', $remote]);
            } catch (SimulatorException) {
            }
        }
    }

    public function open(string $url): void
    {
        $bundle = $this->configuration->bundleId();
        $result = $this->command->run($this->adb(), [
            '-s', $this->serial(), 'shell', 'am', 'start',
            '-a', 'android.intent.action.VIEW',
            '-d', $url,
            $bundle,
        ]);

        if (str_contains($result, 'Error')) {
            $this->command->run($this->adb(), [
                '-s', $this->serial(), 'shell', 'monkey',
                '-p', $bundle,
                '-c', 'android.intent.category.LAUNCHER',
                '1',
            ]);
        }
    }

    public function describe(?string $treePath = null): array
    {
        try {
            $dump = $this->command->run($this->adb(), ['-s', $this->serial(), 'exec-out', 'uiautomator', 'dump', '/dev/tty']);
        } catch (SimulatorException) {
            $dump = '';
        }

        if (! str_contains($dump, '<hierarchy')) {
            $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'uiautomator', 'dump', '/sdcard/uidump.xml']);
            $dump = $this->command->run($this->adb(), ['-s', $this->serial(), 'exec-out', 'cat', '/sdcard/uidump.xml']);
        }

        if ($treePath !== null) {
            file_put_contents($treePath, $dump);
        }

        return $this->dismissSystemDialog($this->elementsFrom($dump), 0);
    }

    /**
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float, 1: float}}>  $elements
     * @return list<array{label: string, role: ?string, id: ?string, center: ?array{0: float, 1: float}}>
     */
    private function dismissSystemDialog(array $elements, int $attempt): array
    {
        if ($attempt >= 2) {
            return $elements;
        }

        foreach ($elements as $element) {
            if ($element['role'] === 'Button' && $element['label'] === 'Wait' && is_array($element['center'] ?? null)) {
                $this->tap((float) $element['center'][0], (float) $element['center'][1]);
                usleep(1_000_000);

                return $this->dismissSystemDialog($this->elementsFrom($this->dumpHierarchy()), $attempt + 1);
            }
        }

        return $elements;
    }

    private function dumpHierarchy(): string
    {
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

    public function tap(float $x, float $y): void
    {
        $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'input', 'tap', (string) $x, (string) $y]);
    }

    public function swipe(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->command->run($this->adb(), [
            '-s', $this->serial(), 'shell', 'input', 'swipe',
            (string) (int) round($x1),
            (string) (int) round($y1),
            (string) (int) round($x2),
            (string) (int) round($y2),
            '300',
        ]);
    }

    public function back(): void
    {
        $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'input', 'keyevent', '4']);
    }

    public function clear(): void
    {
        $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'input', 'keycombination', '113', '29']);
        $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'input', 'keyevent', '67']);
    }

    public function text(string $text): void
    {
        $lines = explode("\n", $text);
        $last = count($lines) - 1;

        foreach ($lines as $index => $line) {
            if ($line !== '') {
                $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'input', 'text', AndroidText::argument($line)]);
            }

            if ($index < $last) {
                $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'input', 'keyevent', '66']);
            }
        }
    }

    public function viewport(): array
    {
        return $this->viewport;
    }

    public function screenshot(string $path): void
    {
        $this->command->run($this->adb(), ['-s', $this->serial(), 'exec-out', 'screencap', '-p'], null);
        $png = $this->command->run($this->adb(), ['-s', $this->serial(), 'exec-out', 'screencap', '-p']);
        file_put_contents($path, $png);
    }

    public function grant(array $services): void
    {
        $bundle = $this->configuration->bundleId();

        foreach (Permissions::targets('android', $services) as $permission) {
            Permissions::attempt(fn () => $this->command->run($this->adb(), [
                '-s', $this->serial(), 'shell', 'pm', 'grant', $bundle, $permission,
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
        $this->launchedEmulator = true;
        $pid = $this->command->start($this->emulator(), ['-avd', $this->device->name, '-no-window', '-no-audio', '-no-snapshot-save'], $log);
        $command = $this->command;
        Shutdown::defer(function () use ($command, $pid): void {
            $command->stop($pid);
        });
        $deadline = microtime(true) + 120;

        while (microtime(true) < $deadline) {
            $booted = $this->bootedAvds();

            if (isset($booted[$this->device->name])) {
                $this->adopt($booted[$this->device->name]);
                $this->command->run($this->adb(), ['-s', $this->serial(), 'wait-for-device']);

                return;
            }

            if (! $this->device->named && $booted !== []) {
                $this->adopt(array_values($booted)[0]);

                return;
            }

            usleep(1_000_000);
        }

        throw new SimulatorException("The Android Emulator [{$this->device->name}] did not boot. See {$log}.");
    }

    /**
     * @return array<string, string>
     */
    private function bootedAvds(): array
    {
        $serials = AvdList::booted($this->command->run($this->adb(), ['devices']));
        $named = [];

        foreach ($serials as $serial) {
            $name = trim($this->command->run($this->adb(), ['-s', $serial, 'emu', 'avd', 'name']));
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

        $arguments = ['artisan', 'native:run', 'android'];

        if ($this->serial !== null) {
            $arguments[] = $this->serial;
        }

        array_push($arguments, '--build=debug', '--no-tty');

        $this->command->run('php', $arguments, $this->configuration->appDirectory());
        self::$built[$key] = true;
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

        foreach ($document->getElementsByTagName('node') as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $nodes[] = [
                'text' => $node->getAttribute('text'),
                'content-desc' => $node->getAttribute('content-desc'),
                'resource-id' => $node->getAttribute('resource-id'),
                'class' => $node->getAttribute('class'),
                'package' => $node->getAttribute('package'),
                'bounds' => $node->getAttribute('bounds'),
                'checked' => $node->getAttribute('checked'),
                'enabled' => $node->getAttribute('enabled'),
                'selected' => $node->getAttribute('selected'),
            ];
        }

        return json_encode($nodes) ?: '[]';
    }

    /**
     * @return list<array{label: string, role: ?string, id: ?string, center: ?array{0: float, 1: float}, value: ?string, enabled: bool, selected: bool, checked: bool, chrome: ?string, webview: bool}>
     */
    private function elementsFrom(string $dump): array
    {
        $this->rememberViewport($dump);

        return AccessibilityTree::summarize($this->xmlToJson($dump));
    }

    private function rememberViewport(string $dump): void
    {
        if (preg_match('/<hierarchy\b([^>]*)>/', $dump, $matches) !== 1) {
            return;
        }

        $width = [];
        $height = [];

        if (preg_match('/\bwidth="([\d.]+)"/', $matches[1], $width) !== 1 || preg_match('/\bheight="([\d.]+)"/', $matches[1], $height) !== 1) {
            return;
        }

        $this->viewport = [(float) $width[1], (float) $height[1]];
    }

    private function adopt(string $serial): void
    {
        $this->serial = $serial;

        if (! $this->launchedEmulator || $this->emulatorTracked) {
            return;
        }

        $this->emulatorTracked = true;
        $adb = $this->adb();
        $command = $this->command;
        Shutdown::defer(function () use ($command, $adb, $serial): void {
            try {
                $command->run($adb, ['-s', $serial, 'emu', 'kill']);
            } catch (SimulatorException) {
            }
        });
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

    private function serial(): string
    {
        return $this->serial ?? throw new SimulatorException('No Android Emulator is selected.');
    }

    private function adb(): string
    {
        return AndroidSdk::binary('platform-tools/adb', 'adb');
    }

    private function emulator(): string
    {
        return AndroidSdk::binary('emulator/emulator', 'emulator');
    }
}
