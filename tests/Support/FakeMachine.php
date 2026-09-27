<?php

declare(strict_types=1);

namespace Tests\Support;

use NativePhp\Simulator\Machine;

final class FakeMachine implements Machine
{
    public function __construct(
        private readonly ?string $xcrun = '/usr/bin/xcrun',
        private readonly ?string $companion = '/opt/homebrew/bin/idb_companion',
        private readonly ?string $sdk = '/Users/dev/Library/Android/sdk',
        private readonly ?string $adb = '/Users/dev/Library/Android/sdk/platform-tools/adb',
        private readonly ?string $emulator = '/Users/dev/Library/Android/sdk/emulator/emulator',
    ) {}

    public function xcrun(): ?string
    {
        return $this->xcrun;
    }

    public function companion(): ?string
    {
        return $this->companion;
    }

    public function androidSdk(): ?string
    {
        return $this->sdk;
    }

    public function adb(): ?string
    {
        return $this->adb;
    }

    public function emulator(): ?string
    {
        return $this->emulator;
    }
}
