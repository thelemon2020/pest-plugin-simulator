<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Exceptions;

final class AmbiguousMatch extends SimulatorException
{
    public function __construct(string $target)
    {
        parent::__construct("[{$target}] matches more than one Button. Pick one with nth(), like ->nth(2)->tap('{$target}'), or look inside the control that holds it with within().");
    }
}
