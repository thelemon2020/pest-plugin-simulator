<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Codegen;

use NativePhp\Simulator\Screen;

/**
 * One call a recorded test makes on its screen, written out and run the same way, so the
 * line the test gets is the line that worked.
 */
final class Step
{
    /**
     * @param  list<string|bool|int|float|array<string, int|float|string|null>>  $arguments
     */
    public function __construct(
        public readonly string $verb,
        public readonly array $arguments = [],
        public readonly ?int $nth = null,
        public readonly ?string $within = null,
    ) {}

    public function code(): string
    {
        $call = ($this->nth === null ? '' : "->nth({$this->nth})")
            .'->'.$this->verb.'('.implode(', ', array_map(self::literal(...), $this->arguments)).')';

        return $this->within === null
            ? $call
            : '->within('.self::literal($this->within).', fn ($screen) => $screen'.$call.')';
    }

    public function perform(Screen $screen): void
    {
        $call = function (Screen $screen): void {
            if ($this->nth !== null) {
                $screen->nth($this->nth);
            }

            $screen->{$this->verb}(...$this->arguments);
        };

        $this->within === null ? $call($screen) : $screen->within($this->within, $call);
    }

    /**
     * A value written as PHP.
     *
     * @param  string|bool|int|float|array<array-key, mixed>|null  $value
     */
    public static function literal(string|bool|int|float|array|null $value): string
    {
        if (is_array($value)) {
            return '['.implode(', ', array_map(
                fn (int|string $key, mixed $item): string => self::literal($key).' => '.self::literal(is_scalar($item) || is_array($item) ? $item : null),
                array_keys($value),
                $value,
            )).']';
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => var_export($value, true),
        };
    }
}
