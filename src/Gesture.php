<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class Gesture
{
    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function scroll(string $direction, float $width, float $height, ?float $distance = null): array
    {
        self::assertDistance($distance);

        $x = $width / 2;
        $span = $height * ($distance ?? 0.5);

        return match ($direction) {
            'down' => [$x, $height * 0.75, $x, self::clamp($height * 0.75 - $span, 1, $height - 1)],
            'up' => [$x, $height * 0.25, $x, self::clamp($height * 0.25 + $span, 1, $height - 1)],
            default => throw new SimulatorException("Scroll [{$direction}] is not up or down."),
        };
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function swipe(string $direction, float $width, float $height, ?float $originX = null, ?float $originY = null, ?float $distance = null): array
    {
        self::assertDistance($distance);

        if ($originX !== null && $originY !== null) {
            $span = $distance === null
                ? min($width, $height) * 0.45
                : self::axis($direction, $width, $height) * $distance;
            [$dx, $dy] = self::delta($direction, $span);

            return [
                $originX,
                $originY,
                self::clamp($originX + $dx, 1, $width - 1),
                self::clamp($originY + $dy, 1, $height - 1),
            ];
        }

        $span = self::axis($direction, $width, $height) * ($distance ?? match ($direction) {
            'down', 'up' => 0.55,
            'left', 'right' => 0.6,
            default => throw new SimulatorException("Swipe [{$direction}] is not up, down, left, or right."),
        });
        [$dx, $dy] = self::delta($direction, $span);

        $startX = match ($direction) {
            'down', 'up' => $width / 2,
            'left' => $width * 0.8,
            'right' => $width * 0.2,
        };
        $startY = match ($direction) {
            'left', 'right' => $height / 2,
            'down' => $height * 0.25,
            'up' => $height * 0.8,
        };

        return [
            $startX,
            $startY,
            self::clamp($startX + $dx, 1, $width - 1),
            self::clamp($startY + $dy, 1, $height - 1),
        ];
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

    private static function axis(string $direction, float $width, float $height): float
    {
        return match ($direction) {
            'left', 'right' => $width,
            'up', 'down' => $height,
            default => throw new SimulatorException("Swipe [{$direction}] is not up, down, left, or right."),
        };
    }

    private static function assertDistance(?float $distance): void
    {
        if ($distance !== null && ($distance <= 0 || $distance > 1)) {
            throw new SimulatorException("Distance [{$distance}] is not between 0 and 1.");
        }
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
