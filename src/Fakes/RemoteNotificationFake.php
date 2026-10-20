<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Illuminate\Support\Collection;
use Illuminate\Support\Testing\Fakes\NotificationFake;

/**
 * Laravel's NotificationFake, whose asserts check the notifications the app sent too (see
 * PullsFromTheApp).
 */
final class RemoteNotificationFake extends NotificationFake
{
    use PullsFromTheApp;

    private const SECTION = 'notifications';

    private const RECORDED = ['notifications'];

    /**
     * @param  mixed  ...$arguments
     */
    public function assertSentTo(...$arguments)
    {
        $this->eventually(fn () => parent::assertSentTo(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertSentToTimes(...$arguments)
    {
        $this->eventually(fn () => parent::assertSentToTimes(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertSentToOnce(...$arguments)
    {
        $this->eventually(fn () => parent::assertSentToOnce(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertSentOnDemand(...$arguments)
    {
        $this->eventually(fn () => parent::assertSentOnDemand(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertSentOnDemandTimes(...$arguments)
    {
        $this->eventually(fn () => parent::assertSentOnDemandTimes(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertSentOnDemandOnce(...$arguments)
    {
        $this->eventually(fn () => parent::assertSentOnDemandOnce(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertSentTimes(...$arguments)
    {
        $this->eventually(fn () => parent::assertSentTimes(...$arguments));
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
    public function assertNotSentTo(...$arguments)
    {
        $this->settled(fn () => parent::assertNotSentTo(...$arguments));
    }

    public function assertNothingSent()
    {
        $this->settled(fn () => parent::assertNothingSent());
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertNothingSentTo(...$arguments)
    {
        $this->settled(fn () => parent::assertNothingSentTo(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     * @return Collection<array-key, mixed>
     */
    public function sent(...$arguments)
    {
        $this->pullOutsideAnAssert();

        return parent::sent(...$arguments);
    }
}
