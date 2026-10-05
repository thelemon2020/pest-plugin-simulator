<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Pest\Contracts\Plugins\Bootable;
use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Contracts\Plugins\Terminable;
use Pest\Plugins\Parallel;
use Pest\TestSuite;
use PHPUnit\Event\Facade as EventFacade;

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
        ParallelLanes::publish();

        if (! Parallel::isEnabled() || Parallel::isWorker()) {
            return;
        }

        Arguments::intercept($_SERVER['argv'] ?? []);

        if (! ParallelLanes::active()) {
            EventFacade::instance()->registerSubscriber(new ParallelLanes);
        }
    }

    /**
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    public function handleArguments(array $arguments): array
    {
        $arguments = Arguments::intercept($arguments);

        if (Arguments::wantsDoctor()) {
            $result = Doctor::check();
            fwrite(STDOUT, $result->render());
            exit($result->successful() ? 0 : 1);
        }

        return $arguments;
    }

    public function terminate(): void
    {
        Shutdown::run();
    }
}
