<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

interface Driver
{
    public function ensureReady(): void;

    public function open(string $url): void;

    /**
     * @return list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>
     */
    public function describe(?string $treePath = null): array;

    public function tap(float $x, float $y): void;

    public function text(string $text): void;

    public function screenshot(string $path): void;
}
