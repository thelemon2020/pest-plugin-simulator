<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Illuminate\Http\Client\Factory;
use NativePhp\Simulator\Run;
use PHPUnit\Framework\Assert as PHPUnit;
use ReflectionClass;
use ReflectionProperty;

/**
 * Laravel's Http client factory, which the plugin binds in place of Laravel's own when the
 * tests boot Laravel. Outside a mobile() test it is that factory and nothing more.
 *
 * Inside one, the app's code is what sends the requests, so it is the app that has to be
 * faked. This factory keeps what the test passed to fake(), which FakeSync sends the app
 * (see Payload), and its asserts check what the app recorded (see Records) along with
 * anything the test sent itself. Http::fake(), Http::fakeSequence(), Http::response(),
 * Http::preventStrayRequests(), and the asserts are Laravel's own, unchanged.
 *
 * @phpstan-import-type Pair from HttpRecords
 */
final class RemoteHttpFactory extends Factory
{
    /**
     * What each fake() was given, in order. A sequence is kept as the object, so responses
     * pushed onto it after fakeSequence() returned still go to the app.
     *
     * @var list<mixed>
     */
    private array $fakes = [];

    /**
     * The pairs this process recorded itself, kept apart from the app's.
     *
     * @var list<array{0: mixed, 1: mixed}>
     */
    private array $local = [];

    private bool $asserting = false;

    /** @var list<bool> whether each sequence the app answers from has run out */
    private array $appSequences = [];

    private bool $faking = false;

    public function fake($callback = null)
    {
        // fake() with an array calls fake() again for each URL, with a closure around the
        // response. Only what the test passed goes to the app.
        if ($this->faking) {
            return parent::fake($callback);
        }

        // Laravel's fake() starts its record again, so this process's part of it goes too.
        $this->fakes[] = $callback;
        $this->local = [];
        $this->faking = true;

        try {
            return parent::fake($callback);
        } finally {
            $this->faking = false;
        }
    }

    public function recordRequestResponsePair($request, $response)
    {
        if ($this->recording) {
            $this->local[] = [$request, $response];
        }

        parent::recordRequestResponsePair($request, $response);
    }

    /**
     * What the app needs to fake its own client the same way, or null when the test has
     * faked nothing.
     *
     * @return array{fakes: list<mixed>, prevent: bool, allow: list<string>}|null
     */
    public function remote(): ?array
    {
        $prevent = $this->preventingStrayRequests();

        if ($this->fakes === [] && ! $prevent) {
            return null;
        }

        // Laravel 12 added the URLs a stray request may still go to.
        $allow = (new ReflectionClass(Factory::class))->hasProperty('allowedStrayRequestUrls')
            ? (new ReflectionProperty(Factory::class, 'allowedStrayRequestUrls'))->getValue($this)
            : [];

        return ['fakes' => $this->fakes, 'prevent' => $prevent, 'allow' => is_array($allow) ? array_values(array_filter($allow, is_string(...))) : []];
    }

    /**
     * Puts what the app recorded beside what this process recorded, for the asserts.
     */
    public function pull(): void
    {
        // Outside a mobile() test the app has recorded nothing, and Laravel's own record is
        // the whole record.
        if (! Run::inside() || FakeSync::test() === null) {
            return;
        }

        $records = Records::pull();
        $sequences = $records['sequences'] ?? [];
        $this->appSequences = is_array($sequences) ? array_values(array_map(boolval(...), $sequences)) : [];
        $http = $records['http'] ?? [];

        if (! is_array($http)) {
            return;
        }

        /** @var list<Pair> $http */
        $this->recorded = [...$this->local, ...HttpRecords::decode($http)];
    }

    public function assertSent($callback)
    {
        $this->asserting(fn () => Records::eventually($this->pull(...), fn () => parent::assertSent($callback)));
    }

    public function assertSentInOrder($callbacks)
    {
        $this->asserting(fn () => Records::eventually($this->pull(...), fn () => parent::assertSentInOrder($callbacks)));
    }

    public function assertSentCount($count)
    {
        $this->asserting(fn () => Records::eventually($this->pull(...), fn () => parent::assertSentCount($count)));
    }

    public function assertNotSent($callback)
    {
        $this->asserting(fn () => Records::settled($this->pull(...), fn () => parent::assertNotSent($callback)));
    }

    public function assertNothingSent()
    {
        $this->asserting(fn () => Records::settled($this->pull(...), fn () => parent::assertNothingSent()));
    }

    /**
     * The app answers from its own copy of each sequence, so it is the app's copies that
     * have to have run out.
     */
    public function assertSequencesAreEmpty()
    {
        if (! Run::inside() || FakeSync::test() === null) {
            parent::assertSequencesAreEmpty();

            return;
        }

        $this->asserting(fn () => Records::settled($this->pull(...), function (): void {
            foreach ($this->appSequences as $empty) {
                PHPUnit::assertTrue($empty, 'Not all response sequences are empty.');
            }
        }));
    }

    /**
     * Http::recorded() in a test reads the app's record file too.
     */
    public function recorded($callback = null)
    {
        if (! $this->asserting && FakeSync::test() !== null) {
            $this->pull();
        }

        return parent::recorded($callback);
    }

    /**
     * Runs an assert that pulls for itself, so the recorded() Laravel's assert calls does
     * not pull again.
     *
     * @param  \Closure(): void  $assert
     */
    private function asserting(\Closure $assert): void
    {
        $this->asserting = true;

        try {
            $assert();
        } finally {
            $this->asserting = false;
        }
    }
}
