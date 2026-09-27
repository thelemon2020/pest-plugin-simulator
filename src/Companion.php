<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class Companion
{
    public static function path(): ?string
    {
        $fromEnv = getenv('IDB_COMPANION');

        if (is_string($fromEnv) && $fromEnv !== '' && is_executable($fromEnv)) {
            return $fromEnv;
        }

        foreach (['/opt/homebrew/bin/idb_companion', '/usr/local/bin/idb_companion'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        $which = trim((string) shell_exec('command -v idb_companion 2>/dev/null'));

        return $which !== '' ? $which : null;
    }

    public static function binary(): string
    {
        return self::path() ?? throw new SimulatorException(
            'idb_companion is not installed. Install it with `brew install idb-companion`.',
        );
    }
}
