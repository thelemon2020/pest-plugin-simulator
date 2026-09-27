<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;
use NativePhp\Simulator\Exceptions\SimulatorException;

final class MobileSuite
{
    private bool $iosTouched = false;

    private bool $androidTouched = false;

    /** @var list<string>|null */
    private ?array $ios = null;

    /** @var list<string>|null */
    private ?array $android = null;

    public function __construct(private readonly Closure $tests) {}

    /**
     * @param  list<string>|null  $names
     */
    public function ios(?array $names = null): self
    {
        $this->iosTouched = true;
        $this->ios = $names;

        return $this;
    }

    /**
     * @param  list<string>|null  $names
     */
    public function android(?array $names = null): self
    {
        $this->androidTouched = true;
        $this->android = $names;

        return $this;
    }

    /**
     * @param  list<string>|null  $ios
     * @param  list<string>|null  $android
     */
    public function devices(?array $ios = null, ?array $android = null): self
    {
        $this->iosTouched = true;
        $this->androidTouched = true;
        $this->ios = $ios;
        $this->android = $android;

        return $this;
    }

    public function __destruct()
    {
        $catalog = DeviceCatalog::resolve();

        try {
            $latest = $this->iosTouched || (! $this->iosTouched && ! $this->androidTouched) ? $catalog->latestIphone() : '';
            $avd = $this->androidTouched || (! $this->iosTouched && ! $this->androidTouched) ? $catalog->defaultAvd() : '';
        } catch (SimulatorException $exception) {
            $latest = '';
            $avd = '';

            if ($this->needsIos() || $this->needsAndroid()) {
                throw $exception;
            }
        }

        SuiteRegistration::run(
            Arguments::select(DevicePlan::resolve($this->iosTouched, $this->ios, $this->androidTouched, $this->android, $latest, $avd)),
            $this->tests,
        );
    }

    private function needsIos(): bool
    {
        return $this->iosTouched || (! $this->iosTouched && ! $this->androidTouched);
    }

    private function needsAndroid(): bool
    {
        return $this->androidTouched || (! $this->iosTouched && ! $this->androidTouched);
    }
}
