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

    /**
     * The build date of the binary at $path (default: the discovered one), from
     * `idb_companion --version` (`{"build_date":"Sep 29 2026","build_time":"..."}`), or
     * null if there is no path, or it cannot be run or parsed.
     *
     * Takes the path explicitly, rather than always resolving it via {@see path()}, so a
     * caller driven by an injected {@see Machine} — {@see Doctor}, in particular — reports
     * on the binary IT was given rather than silently querying whatever this real machine
     * happens to have installed.
     */
    public static function buildDate(?string $path = null): ?string
    {
        $path ??= self::path();

        if ($path === null) {
            return null;
        }

        $output = shell_exec(escapeshellarg($path).' --version 2>/dev/null');

        if (! is_string($output) || trim($output) === '') {
            return null;
        }

        $decoded = json_decode(trim($output), true);

        return is_array($decoded) && is_string($decoded['build_date'] ?? null)
            ? $decoded['build_date']
            : null;
    }

    /**
     * Whether the binary at $path is new enough that reading with `backend=AXBRIDGE` (see
     * Hid::accessibilityInfo()) does not hang.
     *
     * idb_companion before 1.6.3 (built before 2026-09-29) runs a one-shot axbridge guest
     * transport with a hardcoded 30s silence deadline per read — a COMPLETE-format tree
     * read routinely exceeds it, and every read hangs to its own 60s ceiling and comes back
     * with nothing, even though the screen is rendering correctly underneath. 1.6.3 reworked
     * this into a streamed transport and lifted that deadline (facebook/idb commits
     * 3bbe44fe, b4e0a301). There is no semantic version in `--version`'s output, so the
     * build date is the only local signal.
     */
    public static function supportsAxBridge(?string $path = null): ?bool
    {
        $date = self::buildDate($path);

        if ($date === null) {
            return null;
        }

        $built = strtotime($date);

        return $built !== false && $built >= strtotime('2026-09-29');
    }
}
