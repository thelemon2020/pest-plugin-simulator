<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Exceptions;

use RuntimeException;

/**
 * The app logged an error while the test drove it (see AppLog). It is not a
 * SimulatorException, which a step takes for a read that failed and tries again: the app's
 * PHP has failed, and waiting will not change that.
 */
final class AppError extends RuntimeException
{
    /**
     * @param  ?string  $class  the exception the app logged, when it logged one
     */
    public function __construct(string $message, public readonly ?string $class = null)
    {
        parent::__construct($message);
    }
}
