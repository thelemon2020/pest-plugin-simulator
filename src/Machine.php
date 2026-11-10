<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

/** @internal */
interface Machine
{
    public function xcrun(): ?string;

    public function companion(): ?string;

    public function androidSdk(): ?string;

    public function adb(): ?string;

    public function emulator(): ?string;
}
