<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class AndroidText
{
    public static function argument(string $text): string
    {
        return str_replace(['%', ' '], ['\%', '%s'], $text);
    }
}
