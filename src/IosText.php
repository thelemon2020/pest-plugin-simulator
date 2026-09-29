<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class IosText
{
    /**
     * Letters, digits, and spaces go through the hardware keyboard. The email
     * keyboard does not turn Shift-2 into @, and that event drops the rest of
     * the line, so anything else is pasted.
     */
    public static function paste(string $text): bool
    {
        return preg_match('/[^A-Za-z0-9 \n]/u', $text) === 1;
    }
}
