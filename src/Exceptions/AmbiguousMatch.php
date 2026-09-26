<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Exceptions;

final class AmbiguousMatch extends SimulatorException
{
    /**
     * @param  list<array{label: string, role: ?string, id: ?string, center: array{0: float|int, 1: float|int}}>  $matches
     */
    public function __construct(string $target, array $matches)
    {
        parent::__construct("[{$target}] matches more than one Button.");
    }
}
