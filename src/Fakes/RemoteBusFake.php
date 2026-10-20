<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Illuminate\Support\Collection;
use Illuminate\Support\Testing\Fakes\BusFake;

/**
 * Laravel's BusFake, whose asserts check the jobs the app dispatched too (see
 * PullsFromTheApp). Batches stay in the app.
 */
final class RemoteBusFake extends BusFake
{
    use PullsFromTheApp;

    private const SECTION = 'bus';

    private const RECORDED = ['commands', 'commandsSync', 'commandsAfterResponse'];

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
    public function assertDispatchedSync(...$arguments)
    {
        $this->eventually(fn () => parent::assertDispatchedSync(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertDispatchedSyncTimes(...$arguments)
    {
        $this->eventually(fn () => parent::assertDispatchedSyncTimes(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertDispatchedAfterResponse(...$arguments)
    {
        $this->eventually(fn () => parent::assertDispatchedAfterResponse(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertDispatchedAfterResponseTimes(...$arguments)
    {
        $this->eventually(fn () => parent::assertDispatchedAfterResponseTimes(...$arguments));
    }

    /**
     * @param  array<mixed>  $expectedChain
     */
    public function assertChained(array $expectedChain)
    {
        $this->eventually(fn () => parent::assertChained($expectedChain));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertDispatchedWithoutChain(...$arguments)
    {
        $this->eventually(fn () => parent::assertDispatchedWithoutChain(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertNotDispatched(...$arguments)
    {
        $this->settled(fn () => parent::assertNotDispatched(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertNotDispatchedSync(...$arguments)
    {
        $this->settled(fn () => parent::assertNotDispatchedSync(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertNotDispatchedAfterResponse(...$arguments)
    {
        $this->settled(fn () => parent::assertNotDispatchedAfterResponse(...$arguments));
    }

    public function assertNothingDispatched()
    {
        $this->settled(fn () => parent::assertNothingDispatched());
    }

    public function assertNothingChained()
    {
        $this->settled(fn () => parent::assertNothingChained());
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
