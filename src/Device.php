<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class Device
{
    public function __construct(
        public readonly string $platform,
        public readonly string $name,
        public readonly bool $named,
    ) {}

    public function key(): string
    {
        return $this->platform.':'.($this->named ? '1' : '0').':'.$this->name;
    }

    public function identity(): string
    {
        return $this->platform.':'.$this->name;
    }

    public static function fromKey(string $key): self
    {
        $parts = explode(':', $key, 3);

        if (count($parts) === 2) {
            [$platform, $name] = $parts;
            $named = true;
        } else {
            [$platform, $namedFlag, $name] = $parts;
            $named = $namedFlag === '1';
        }

        if (($platform !== 'ios' && $platform !== 'android') || $name === '') {
            throw new SimulatorException("Unknown device [{$key}].");
        }

        return new self($platform, $name, $named);
    }
}
