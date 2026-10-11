<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Exceptions;

final class NoMatch extends SimulatorException
{
    public function __construct(string $target)
    {
        parent::__construct("Could not find [{$target}].");
    }
}
