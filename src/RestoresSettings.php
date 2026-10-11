<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;

/**
 * Putting a setting back means the device's own value, which need not be the platform's
 * default: a Simulator someone keeps in dark mode stays dark. So a driver reads the value
 * before its first change, and writes it back when DeviceState lets the setting go.
 */
trait RestoresSettings
{
    /**
     * What the device had before this driver changed it, by setting.
     *
     * @var array<string, string>
     */
    private array $own = [];

    /**
     * Writes $value, or with null writes back the value read before the first change.
     *
     * @param  Closure(): string  $read
     * @param  Closure(string): void  $write
     */
    private function setting(string $name, ?string $value, Closure $read, Closure $write): void
    {
        if ($value === null) {
            if (! array_key_exists($name, $this->own)) {
                return;
            }

            $write($this->own[$name]);
            unset($this->own[$name]);

            return;
        }

        if (! array_key_exists($name, $this->own)) {
            $this->own[$name] = $read();
        }

        $write($value);
    }
}
