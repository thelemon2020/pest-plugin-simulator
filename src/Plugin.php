<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Pest\Contracts\Plugins\Bootable;
use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Contracts\Plugins\Terminable;
use Pest\TestSuite;

final class Plugin implements Bootable, HandlesArguments, Terminable
{
    private static bool $booted = false;

    public function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
        TestSuite::getInstance()->tests->addTestCaseMethodFilter(new MobileTestFilter);
    }

    /**
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    public function handleArguments(array $arguments): array
    {
        return $arguments;
    }

    public function terminate(): void {}
}
