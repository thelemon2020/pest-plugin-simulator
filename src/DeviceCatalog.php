<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class DeviceCatalog
{
    private static ?self $instance = null;

    private static ?string $latestIphone = null;

    private static ?string $defaultAvd = null;

    public function __construct(
        private readonly ?Command $command = null,
    ) {}

    public static function use(self $catalog): void
    {
        self::$instance = $catalog;
    }

    public static function fake(string $latestIphone, string $defaultAvd): void
    {
        self::$latestIphone = $latestIphone;
        self::$defaultAvd = $defaultAvd;
        self::$instance = new self;
    }

    public static function resolve(): self
    {
        return self::$instance ??= new self(new Command);
    }

    public function latestIphone(): string
    {
        if (self::$latestIphone !== null) {
            return self::$latestIphone;
        }

        return SimulatorList::latestIphone($this->simctlJson());
    }

    public function defaultAvd(): string
    {
        if (self::$defaultAvd !== null) {
            return self::$defaultAvd;
        }

        $avds = AvdList::names($this->command()->run($this->emulatorBinary(), ['-list-avds']));

        return $avds[0] ?? throw new SimulatorException('No Android AVD is installed.');
    }

    private function simctlJson(): string
    {
        return $this->command()->run('xcrun', ['simctl', 'list', 'devices', 'available', '-j']);
    }

    private function command(): Command
    {
        return $this->command ?? new Command;
    }

    private function emulatorBinary(): string
    {
        return AndroidSdk::binary('emulator/emulator', 'emulator');
    }
}
