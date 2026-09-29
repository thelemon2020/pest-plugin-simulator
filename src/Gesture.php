<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class Gesture
{
    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function scroll(string $direction, float $width, float $height): array
    {
        $x = $width / 2;

        return match ($direction) {
            'down' => [$x, $height * 0.75, $x, $height * 0.25],
            'up' => [$x, $height * 0.25, $x, $height * 0.75],
            default => throw new SimulatorException("Scroll [{$direction}] is not up or down."),
        };
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function swipe(string $direction, float $width, float $height, ?float $originX = null, ?float $originY = null): array
    {
        if ($originX !== null && $originY !== null) {
            $distance = min($width, $height) * 0.45;
            [$dx, $dy] = self::delta($direction, $distance);

            return [
                $originX,
                $originY,
                self::clamp($originX + $dx, 1, $width - 1),
                self::clamp($originY + $dy, 1, $height - 1),
            ];
        }

        return match ($direction) {
            'down' => [$width / 2, $height * 0.25, $width / 2, $height * 0.8],
            'up' => [$width / 2, $height * 0.8, $width / 2, $height * 0.25],
            'left' => [$width * 0.8, $height / 2, $width * 0.2, $height / 2],
            'right' => [$width * 0.2, $height / 2, $width * 0.8, $height / 2],
            default => throw new SimulatorException("Swipe [{$direction}] is not up, down, left, or right."),
        };
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function back(float $width, float $height): array
    {
        // iOS only treats the drag as Back when the finger starts on the bezel.
        return [1.0, $height / 2, $width * 0.7, $height / 2];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function delta(string $direction, float $distance): array
    {
        return match ($direction) {
            'down' => [0.0, $distance],
            'up' => [0.0, -$distance],
            'left' => [-$distance, 0.0],
            'right' => [$distance, 0.0],
            default => throw new SimulatorException("Swipe [{$direction}] is not up, down, left, or right."),
        };
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
