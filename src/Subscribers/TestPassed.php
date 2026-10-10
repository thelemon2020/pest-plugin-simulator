<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Subscribers;

use NativePhp\Simulator\Recording;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;

/**
 * PHPUnit says a test passed only after its tearDown, so an afterEach() that fails still
 * keeps the clip record_failures made.
 */
final class TestPassed implements PassedSubscriber
{
    public function notify(Passed $event): void
    {
        Recording::passed();
    }
}
