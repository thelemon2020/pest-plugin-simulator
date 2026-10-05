<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class SnapshotWriter
{
    /** @var array<string, resource> */
    private static array $locks = [];

    /**
     * One process per AVD may boot writable and save the Quick Boot snapshot.
     * The lock is held until the process exits, or until reset() in tests.
     */
    public static function claim(string $avd): bool
    {
        if (isset(self::$locks[$avd])) {
            return true;
        }

        $handle = fopen(self::path($avd), 'c');

        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return false;
        }

        self::$locks[$avd] = $handle;

        return true;
    }

    public static function path(string $avd): string
    {
        return sys_get_temp_dir().'/pest-simulator-snapshot-'.hash('sha256', $avd).'.lock';
    }

    public static function reset(): void
    {
        foreach (self::$locks as $handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        self::$locks = [];
    }
}
