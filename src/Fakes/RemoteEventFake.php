<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Illuminate\Support\Collection;
use Illuminate\Support\Testing\Fakes\EventFake;

/**
 * Laravel's EventFake, whose asserts check the events the app dispatched too (see
 * PullsFromTheApp). The app's own fake always lets NativePHP's `Native\` events through,
 * so they are never in its record.
 */
final class RemoteEventFake extends EventFake
{
    use PullsFromTheApp;

    private const SECTION = 'events';

    private const RECORDED = ['events'];

    /**
     * @param  mixed  ...$arguments
     */
    public function assertDispatched(...$arguments)
    {
        $this->eventually(fn () => parent::assertDispatched(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertDispatchedTimes(...$arguments)
    {
        $this->eventually(fn () => parent::assertDispatchedTimes(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertDispatchedOnce(...$arguments)
    {
        $this->eventually(fn () => parent::assertDispatchedOnce(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertNotDispatched(...$arguments)
    {
        $this->settled(fn () => parent::assertNotDispatched(...$arguments));
    }

    public function assertNothingDispatched()
    {
        $this->settled(fn () => parent::assertNothingDispatched());
    }

    /**
     * @param  mixed  ...$arguments
     * @return Collection<array-key, mixed>
     */
    public function dispatched(...$arguments)
    {
        $this->pullOutsideAnAssert();

        return parent::dispatched(...$arguments);
    }
}
