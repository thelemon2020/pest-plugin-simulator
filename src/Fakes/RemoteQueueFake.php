<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Illuminate\Support\Collection;
use Illuminate\Support\Testing\Fakes\QueueFake;

/**
 * Laravel's QueueFake, whose asserts check the jobs the app pushed too (see
 * PullsFromTheApp).
 */
final class RemoteQueueFake extends QueueFake
{
    use PullsFromTheApp;

    private const SECTION = 'queue';

    private const RECORDED = ['jobs'];

    private const DEPTH = 2;

    /**
     * @param  mixed  ...$arguments
     */
    public function assertPushed(...$arguments)
    {
        $this->eventually(fn () => parent::assertPushed(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertPushedTimes(...$arguments)
    {
        $this->eventually(fn () => parent::assertPushedTimes(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertPushedOnce(...$arguments)
    {
        $this->eventually(fn () => parent::assertPushedOnce(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertPushedOn(...$arguments)
    {
        $this->eventually(fn () => parent::assertPushedOn(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertPushedWithChain(...$arguments)
    {
        $this->eventually(fn () => parent::assertPushedWithChain(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertPushedWithoutChain(...$arguments)
    {
        $this->eventually(fn () => parent::assertPushedWithoutChain(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertClosurePushed(...$arguments)
    {
        $this->eventually(fn () => parent::assertClosurePushed(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertCount(...$arguments)
    {
        $this->eventually(fn () => parent::assertCount(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertNotPushed(...$arguments)
    {
        $this->settled(fn () => parent::assertNotPushed(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertClosureNotPushed(...$arguments)
    {
        $this->settled(fn () => parent::assertClosureNotPushed(...$arguments));
    }

    public function assertNothingPushed()
    {
        $this->settled(fn () => parent::assertNothingPushed());
    }

    /**
     * @param  mixed  ...$arguments
     * @return Collection<array-key, mixed>
     */
    public function pushed(...$arguments)
    {
        $this->pullOutsideAnAssert();

        return parent::pushed(...$arguments);
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function hasPushed(...$arguments)
    {
        $this->pullOutsideAnAssert();

        return parent::hasPushed(...$arguments);
    }

    /**
     * @param  mixed  ...$arguments
     * @return array<array-key, mixed>
     */
    public function pushedJobs(...$arguments)
    {
        $this->pullOutsideAnAssert();

        return parent::pushedJobs(...$arguments);
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function pushedRaw(...$arguments)
    {
        $this->pullOutsideAnAssert();

        return parent::pushedRaw(...$arguments);
    }
}
