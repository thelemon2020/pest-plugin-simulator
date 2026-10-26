<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Codegen;

/**
 * The test a codegen session has recorded so far: the screen it opened and each step after,
 * with a comment where a step led to a screen with another title.
 */
final class Script
{
    /** @var list<Step|string> steps, and the titles of the screens they led to */
    private array $lines = [];

    public function __construct(public readonly string $path) {}

    public function add(Step $step): void
    {
        $this->lines[] = $step;
    }

    /**
     * Notes the screen the steps after this one are on.
     */
    public function screen(string $title): void
    {
        $this->lines[] = $title;
    }

    /**
     * Drops the last step, and the title of the screen it led to.
     */
    public function undo(): void
    {
        while ($this->lines !== [] && is_string(end($this->lines))) {
            array_pop($this->lines);
        }

        array_pop($this->lines);
    }

    public function chain(): string
    {
        $lines = ['screen('.var_export($this->path, true).')'];

        foreach ($this->lines as $line) {
            // A title is a comment, so a line break in one cannot end it early.
            $lines[] = '    '.(is_string($line) ? '// '.preg_replace('/\s+/', ' ', $line) : $line->code());
        }

        // A chain cannot end on a comment: the semicolon would be part of it.
        while (str_starts_with(end($lines) ?: '', '    //')) {
            array_pop($lines);
        }

        return implode("\n", $lines).';';
    }
}
