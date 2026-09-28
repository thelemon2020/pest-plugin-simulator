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

    public function swipe(float $x1, float $y1, float $x2, float $y2): void;

    public function back(): void;

    public function clear(): void;

    public function text(string $text): void;

    /**
     * @return array{0: float, 1: float}
     */
    public function viewport(): array;

    public function screenshot(string $path): void;

    public function installDatabase(string $sqlitePath): void;
}
