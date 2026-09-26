<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class AvdList
{
    /**
     * @return list<string>
     */
    public static function names(string $output): array
    {
        $names = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && ! str_starts_with($line, 'INFO')) {
                $names[] = $line;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public static function booted(string $adbDevices): array
    {
        $serials = [];

        foreach (preg_split('/\R/', $adbDevices) ?: [] as $line) {
            if (preg_match('/^(emulator-\d+)\s+device$/', trim($line), $matches) === 1) {
                $serials[] = $matches[1];
            }
        }

        return $serials;
    }
}
