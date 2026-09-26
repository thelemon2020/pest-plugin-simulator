<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class AndroidDriver implements Driver
{
    private static array $built = [];

    private ?string $serial = null;

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

        return $this->dismissSystemDialog(AccessibilityTree::summarize($this->xmlToJson($dump)), 0);
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

                return $this->dismissSystemDialog(AccessibilityTree::summarize($this->xmlToJson($this->dumpHierarchy())), $attempt + 1);
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

    public function text(string $text): void
    {
        $escaped = str_replace([' ', '%', '&', '<', '>', '|', ';'], ['%s', '\%', '\&', '\&lt;', '\&gt;', '\|', '\;'], $text);
        $this->command->run($this->adb(), ['-s', $this->serial(), 'shell', 'input', 'text', $escaped]);
    }

    public function screenshot(string $path): void
    {
        $this->command->run($this->adb(), ['-s', $this->serial(), 'exec-out', 'screencap', '-p'], null);
        $png = $this->command->run($this->adb(), ['-s', $this->serial(), 'exec-out', 'screencap', '-p']);
        file_put_contents($path, $png);
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
        $this->command->start($this->emulator(), ['-avd', $this->device->name, '-no-window', '-no-audio', '-no-snapshot-save'], $log);
        $deadline = microtime(true) + 120;

        while (microtime(true) < $deadline) {
            $booted = $this->bootedAvds();

            if (isset($booted[$this->device->name])) {
                $this->serial = $booted[$this->device->name];
                $this->command->run($this->adb(), ['-s', $this->serial, 'wait-for-device']);

                return;
            }

            if (! $this->device->named && $booted !== []) {
                $this->serial = array_values($booted)[0];

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
                'bounds' => $node->getAttribute('bounds'),
            ];
        }

        return json_encode($nodes) ?: '[]';
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
