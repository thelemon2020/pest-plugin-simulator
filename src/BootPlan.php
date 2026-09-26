<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class BootPlan
{
    /**
     * @param  list<string>  $bootedNames
     * @return list<string> names to shut down before booting $target
     */
    public static function shutdowns(bool $named, string $target, array $bootedNames): array
    {
        if (! $named && count($bootedNames) > 1) {
            throw new SimulatorException(
                'More than one device of this platform is booted. Name one with ios() or android(). Booted: '.implode(', ', $bootedNames),
            );
        }

        if (! $named) {
            return [];
        }

        return array_values(array_filter(
            $bootedNames,
            fn (string $name): bool => $name !== $target,
        ));
    }

    public static function shouldBoot(bool $named, string $target, array $bootedNames): bool
    {
        self::shutdowns($named, $target, $bootedNames);

        if (! $named && $bootedNames !== []) {
            return false;
        }

        return ! in_array($target, $bootedNames, true);
    }
}
