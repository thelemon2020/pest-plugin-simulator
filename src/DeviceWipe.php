<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class DeviceWipe
{
    /**
     * Cold-boot from an empty userdata image. Loading the Quick Boot
     * snapshot would restore the files this is meant to drop.
     *
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public static function emulator(array $arguments): array
    {
        return array_merge($arguments, ['-wipe-data', '-no-snapshot-load']);
    }

    public static function eraseSimulator(Command $command, string $udid): void
    {
        self::shutdownSimulator($command, $udid);
        $command->run('xcrun', ['simctl', 'erase', $udid]);
    }

    public static function deleteSimulator(Command $command, string $udid): void
    {
        self::shutdownSimulator($command, $udid);
        $command->run('xcrun', ['simctl', 'delete', $udid]);
    }

    private static function shutdownSimulator(Command $command, string $udid): void
    {
        try {
            $command->run('xcrun', ['simctl', 'shutdown', $udid]);
        } catch (SimulatorException) {
        }
    }
}
