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
 * A class that uses it names its record section in SECTION and the arrays in RECORDED. Its
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
            $local = self::without(is_array($value) ? $value : [], $this->pulled[$name] ?? []);
            $remote = is_array($section[$name] ?? null) ? Records::entries($section[$name]) : [];
            $this->pulled[$name] = $remote;
            self::recorded($name)->setValue($this, self::merge($local, $remote));
        }
    }

    /**
     * Two records as one. A list goes on with the other's entries; anything else keeps its
     * keys, as a notifiable's key, which PHP turns into a number, is a key and not a place.
     *
     * @param  array<array-key, mixed>  $local
     * @param  array<array-key, mixed>  $remote
     * @return array<array-key, mixed>
     */
    private static function merge(array $local, array $remote): array
    {
        if ($local === []) {
            return $remote;
        }

        if (array_is_list($local) && array_is_list($remote)) {
            return [...$local, ...$remote];
        }

        foreach ($remote as $key => $value) {
            $local[$key] = is_array($value) && is_array($local[$key] ?? null) ? self::merge($local[$key], $value) : $value;
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
    private static function without(array $record, array $pulled): array
    {
        if ($pulled === []) {
            return $record;
        }

        if (array_is_list($record) && array_is_list($pulled)) {
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

            $record[$key] = self::without($record[$key], $value);

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
