<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Subscribers;

use NativePhp\Simulator\Recording;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

final class TestFinished implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        Recording::finished();
    }
}
