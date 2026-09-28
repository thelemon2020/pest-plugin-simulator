<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class AndroidText
{
    public static function argument(string $text): string
    {
        // input text reads %s as a space and \% as a percent. The device
        // shell parses the command first, so the token is single-quoted
        // and an apostrophe is escaped for sh.
        $encoded = str_replace(['%', ' '], ['\%', '%s'], $text);

        return "'".str_replace("'", "'\\''", $encoded)."'";
    }
}
