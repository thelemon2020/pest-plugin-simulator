<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Codegen;

/**
 * The test a codegen session has recorded so far: the screen it opened and each step after.
 */
final class Script
{
    /** @var list<Step> */
    private array $steps = [];

    public function __construct(public readonly string $path) {}

    public function add(Step $step): void
    {
        $this->steps[] = $step;
    }

    public function undo(): void
    {
        array_pop($this->steps);
    }

    public function chain(): string
    {
        $lines = ['screen('.var_export($this->path, true).')'];

        foreach ($this->steps as $step) {
            $lines[] = '    '.$step->code();
        }

        return implode("\n", $lines).';';
    }
}
