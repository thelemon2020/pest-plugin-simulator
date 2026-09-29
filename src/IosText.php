<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class IosText
{
    /**
     * Letters, digits, and spaces go through the hardware keyboard. The email
     * keyboard does not turn Shift-2 into @, and that event drops the rest of
     * the line, so anything else is tapped from the keys on screen.
     */
    public static function paste(string $text): bool
    {
        return preg_match('/[^A-Za-z0-9 \n]/u', $text) === 1;
    }

    /**
     * @return list<string>
     */
    public static function pieces(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($characters === false) {
            return [$text];
        }

        $pieces = [];
        $buffer = '';

        foreach ($characters as $character) {
            if (! self::paste($character)) {
                $buffer .= $character;

                continue;
            }

            if ($buffer !== '') {
                $pieces[] = $buffer;
                $buffer = '';
            }

            $pieces[] = $character;
        }

        if ($buffer !== '') {
            $pieces[] = $buffer;
        }

        return $pieces;
    }
}
