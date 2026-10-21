<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Closure;
use NativePhp\Simulator\Run;
use ReflectionClass;
use ReflectionProperty;

/**
 * What a Remote fake adds to the Laravel fake it extends: the app's record goes into the
 * arrays Laravel's own asserts read, before each assert.
 *
 * A class that uses it names its record section in SECTION, the arrays in RECORDED, and in
 * DEPTH how deep in them the lists of entries are, as Mirror writes them. Its
 * positive asserts call eventually(), which reads the record again until the assert passes
 * or the timeout runs out. Its negative asserts call settled(), which reads it once, after
 * the app has taken every event it was sent.
 */
trait PullsFromTheApp
{
    /**
     * What the last pull added from the app, by array, so the next pull can tell it from
     * what this process recorded itself since.
     *
     * @var array<string, array<array-key, mixed>>
     */
    private array $pulled = [];

    /** Which of the test's fakes this is: faking again makes another. */
    private int $generation = 0;

    private static int $generations = 0;

    private bool $asserting = false;

    /**
     * The same fake, as this Remote one. Laravel's Mail::fake() and the rest make their own
     * fake, so FakeSync swaps it for this copy of it at the next step.
     */
    public static function from(object $fake): static
    {
        $remote = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();

        for ($class = new ReflectionClass($fake); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if (! $property->isStatic() && $property->getDeclaringClass()->getName() === $class->getName() && $property->isInitialized($fake)) {
                    $property->setValue($remote, $property->getValue($fake));
                }
            }
        }

        $remote->generation = ++self::$generations;

        // The test may have kept the fake Mail::fake() returned, and assert through it.
        // Its record arrays are this one's, so it sees what this one pulls from the app.
        $share = function (object $from, object $to, string $name): void {
            $from->{$name} = &$to->{$name};
        };

        foreach (self::RECORDED as $name) {
            if (property_exists(parent::class, $name)) {
                Closure::bind($share, null, parent::class)($fake, $remote, $name);
            }
        }

        return $remote;
    }

    public function generation(): int
    {
        return $this->generation;
    }

    /**
     * Puts the app's record beside what this process did itself, before and since. A record
     * from before the test faked again is not this fake's.
     */
    public function pull(): void
    {
        if (! Run::inside() || FakeSync::test() === null) {
            return;
        }

        $section = Records::pull()[self::SECTION] ?? null;

        if (! is_array($section) || ($section['generation'] ?? null) !== $this->generation) {
            $section = [];
        }

        foreach (self::RECORDED as $name) {
            if (! property_exists(parent::class, $name)) {
                continue;
            }

            $value = self::recorded($name)->getValue($this);
            $local = self::without(is_array($value) ? $value : [], $this->pulled[$name] ?? [], self::DEPTH);
            $remote = is_array($section[$name] ?? null) ? Models::reading(fn (): array => Records::entries($section[$name])) : [];
            $this->pulled[$name] = $remote;
            self::recorded($name)->setValue($this, self::merge($local, $remote, self::DEPTH));
        }
    }

    /**
     * Two records as one. At $depth the entries are a list, and the other's go on after
     * this one's. Above it every array is keyed, by a class or a notifiable's key, and
     * the two are merged by key, even when the keys PHP made of ids look like a list.
     *
     * @param  array<array-key, mixed>  $local
     * @param  array<array-key, mixed>  $remote
     * @return array<array-key, mixed>
     */
    private static function merge(array $local, array $remote, int $depth): array
    {
        if ($depth <= 1) {
            return [...array_values($local), ...array_values($remote)];
        }

        foreach ($remote as $key => $value) {
            $local[$key] = is_array($value) && is_array($local[$key] ?? null) ? self::merge($local[$key], $value, $depth - 1) : $value;
        }

        return $local;
    }

    /**
     * A record without what an earlier pull put in it: what is left is what this process
     * recorded itself. An array emptied that way goes too.
     *
     * @param  array<array-key, mixed>  $record
     * @param  array<array-key, mixed>  $pulled
     * @return array<array-key, mixed>
     */
    private static function without(array $record, array $pulled, int $depth): array
    {
        if ($pulled === []) {
            return $record;
        }

        if ($depth <= 1) {
            foreach ($pulled as $entry) {
                $at = array_search($entry, $record, true);

                if ($at !== false) {
                    unset($record[$at]);
                }
            }

            return array_values($record);
        }

        foreach ($pulled as $key => $value) {
            if (! is_array($value) || ! is_array($record[$key] ?? null)) {
                continue;
            }

            $record[$key] = self::without($record[$key], $value, $depth - 1);

            if ($record[$key] === []) {
                unset($record[$key]);
            }
        }

        return $record;
    }

    /**
     * @param  Closure(): void  $assert
     */
    private function eventually(Closure $assert): void
    {
        $this->asserting(fn () => Records::eventually($this->pull(...), $assert));
    }

    /**
     * @param  Closure(): void  $assert
     */
    private function settled(Closure $assert): void
    {
        $this->asserting(fn () => Records::settled($this->pull(...), $assert));
    }

    /**
     * Mail::sent(), Event::dispatched(), and the rest read the app's record too, unless an
     * assert that has just read it is calling them.
     */
    private function pullOutsideAnAssert(): void
    {
        if (! $this->asserting && FakeSync::test() !== null) {
            $this->pull();
        }
    }

    /**
     * @param  Closure(): void  $assert
     */
    private function asserting(Closure $assert): void
    {
        $this->asserting = true;

        try {
            $assert();
        } finally {
            $this->asserting = false;
        }
    }

    private static function recorded(string $name): ReflectionProperty
    {
        return new ReflectionProperty(parent::class, $name);
    }
}
