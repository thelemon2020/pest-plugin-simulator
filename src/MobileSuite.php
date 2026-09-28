<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;

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
        SuiteRegistration::run(
            Arguments::select(SuitePlan::devices(
                $this->iosTouched,
                $this->ios,
                $this->androidTouched,
                $this->android,
                Platforms::runnable(),
                DeviceCatalog::resolve(),
            )),
            $this->tests,
        );
    }
}
