<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class LocalMachine implements Machine
{
    public function xcrun(): ?string
    {
        return $this->which('xcrun');
    }

    public function companion(): ?string
    {
        return Companion::path();
    }

    public function androidSdk(): ?string
    {
        $home = AndroidSdk::home();

        return $home !== '' ? $home : null;
    }

    public function adb(): ?string
    {
        return $this->executable('platform-tools/adb', 'adb');
    }

    public function emulator(): ?string
    {
        return $this->executable('emulator/emulator', 'emulator');
    }

    private function executable(string $relative, string $fallback): ?string
    {
        $home = AndroidSdk::home();
        $path = $home === '' ? '' : $home.'/'.$relative;

        if ($path !== '' && is_executable($path)) {
            return $path;
        }

        return $this->which($fallback);
    }

    private function which(string $binary): ?string
    {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null'));

        return $path !== '' ? $path : null;
    }
}
