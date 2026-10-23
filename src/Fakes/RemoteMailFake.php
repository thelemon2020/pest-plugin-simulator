<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Illuminate\Support\Collection;
use Illuminate\Support\Testing\Fakes\MailFake;

/**
 * Laravel's MailFake, whose asserts check the mail the app sent too (see PullsFromTheApp).
 * FakeSync puts it in place of the MailFake that Mail::fake() made, at the next step.
 */
final class RemoteMailFake extends MailFake
{
    use PullsFromTheApp;

    private const SECTION = 'mail';

    private const RECORDED = ['mailables' => 1, 'queuedMailables' => 1];

    /**
     * @param  mixed  ...$arguments
     */
    public function assertSent(...$arguments)
    {
        $this->eventually(fn () => parent::assertSent(...$arguments));
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
    public function assertSentOnce(...$arguments)
    {
        $this->eventually(fn () => parent::assertSentOnce(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertSentCount(...$arguments)
    {
        $this->eventually(fn () => parent::assertSentCount(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertQueued(...$arguments)
    {
        $this->eventually(fn () => parent::assertQueued(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertQueuedTimes(...$arguments)
    {
        $this->eventually(fn () => parent::assertQueuedTimes(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertQueuedOnce(...$arguments)
    {
        $this->eventually(fn () => parent::assertQueuedOnce(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertQueuedCount(...$arguments)
    {
        $this->eventually(fn () => parent::assertQueuedCount(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertOutgoingCount(...$arguments)
    {
        $this->eventually(fn () => parent::assertOutgoingCount(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertNotSent(...$arguments)
    {
        $this->settled(fn () => parent::assertNotSent(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertNotQueued(...$arguments)
    {
        $this->settled(fn () => parent::assertNotQueued(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertNotOutgoing(...$arguments)
    {
        $this->settled(fn () => parent::assertNotOutgoing(...$arguments));
    }

    public function assertNothingSent()
    {
        $this->settled(fn () => parent::assertNothingSent());
    }

    public function assertNothingQueued()
    {
        $this->settled(fn () => parent::assertNothingQueued());
    }

    public function assertNothingOutgoing()
    {
        $this->settled(fn () => parent::assertNothingOutgoing());
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

    /**
     * @param  mixed  ...$arguments
     * @return Collection<array-key, mixed>
     */
    public function queued(...$arguments)
    {
        $this->pullOutsideAnAssert();

        return parent::queued(...$arguments);
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function hasSent(...$arguments)
    {
        $this->pullOutsideAnAssert();

        return parent::hasSent(...$arguments);
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function hasQueued(...$arguments)
    {
        $this->pullOutsideAnAssert();

        return parent::hasQueued(...$arguments);
    }
}
