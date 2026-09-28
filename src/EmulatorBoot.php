<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class EmulatorBoot
{
    public const int TIMEOUT_SECONDS = 300;

    /**
     * @return list<string>
     */
    public static function arguments(string $avd, ?int $port = null): array
    {
        $arguments = [
            '-avd', $avd,
            '-no-window',
            '-no-audio',
            '-no-snapshot-save',
            '-no-snapshot-load',
            '-wipe-data',
            '-gpu', 'swiftshader_indirect',
        ];

        if ($port !== null) {
            $arguments[] = '-read-only';
            $arguments[] = '-port';
            $arguments[] = (string) $port;
        }

        return $arguments;
    }
}
