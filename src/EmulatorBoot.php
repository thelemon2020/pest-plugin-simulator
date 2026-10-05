<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class EmulatorBoot
{
    public const int TIMEOUT_SECONDS = 300;

    /**
     * Quick Boot when the AVD already has a snapshot. Wiping userdata and
     * forcing a cold boot deleted the installed debug build, so the next run
     * compiled it again.
     *
     * A parallel worker passes a port. Read-only workers can share one AVD,
     * and a read-only emulator cannot write the snapshot. One worker boots
     * writable ($saveSnapshot) so the exit saves a snapshot the others load.
     *
     * @return list<string>
     */
    public static function arguments(string $avd, ?int $port = null, bool $saveSnapshot = false): array
    {
        $arguments = [
            '-avd', $avd,
            '-no-window',
            '-no-audio',
            '-no-boot-anim',
            '-no-metrics',
            '-gpu', 'swiftshader_indirect',
        ];

        if ($port !== null) {
            if (! $saveSnapshot) {
                $arguments[] = '-read-only';
                $arguments[] = '-no-snapshot-save';
            }

            $arguments[] = '-port';
            $arguments[] = (string) $port;
        }

        return $arguments;
    }
}
