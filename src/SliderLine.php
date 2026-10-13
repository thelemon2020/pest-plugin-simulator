<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

/**
 * Where on screen a slider takes a value, as slide() learns it: a straight line from points
 * along the track to values, drawn through the last two places a drag let go and the values
 * it left. Before any drag, it is the track's own line.
 */
final class SliderLine
{
    /**
     * @param  float  $x  a point along the track
     * @param  float  $value  the value the slider takes there
     * @param  float  $perUnit  how far along the track one unit of value is
     * @param  array{0: float, 1: float}|null  $last  the last point a drag let go, and its value
     */
    public function __construct(
        private readonly float $x,
        private readonly float $value,
        private readonly float $perUnit,
        private readonly ?array $last = null,
    ) {}

    /**
     * The point along the track where the slider takes a value.
     */
    public function at(float $value): float
    {
        return $this->x + ($value - $this->value) * $this->perUnit;
    }

    /**
     * The line through this point and the last one. One point only moves the line; two that
     * moved the value pin its slope as well. A slope that comes out backwards, from a drag
     * that moved nothing it could measure, keeps the one before.
     */
    public function through(float $x, float $value): self
    {
        $perUnit = $this->perUnit;

        if ($this->last !== null && $value !== $this->last[1]) {
            $measured = ($x - $this->last[0]) / ($value - $this->last[1]);

            if ($measured > 0 && is_finite($measured)) {
                $perUnit = $measured;
            }
        }

        return new self($x, $value, $perUnit, [$x, $value]);
    }
}
