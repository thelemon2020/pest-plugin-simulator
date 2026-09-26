<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Exceptions;

final class NoMatch extends SimulatorException
{
    /**
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>  $elements
     */
    public function __construct(string $target, array $elements)
    {
        parent::__construct("Could not find [{$target}].");
    }
}
