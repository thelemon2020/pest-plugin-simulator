<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Closure;
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
     * What the fake held when it became a Remote one: what this process did itself.
     *
     * @var array<string, array<array-key, mixed>>
     */
    private array $local = [];

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

        foreach (self::RECORDED as $name) {
            $value = self::recorded($name)->getValue($remote);
            $remote->local[$name] = is_array($value) ? $value : [];
        }

        return $remote;
    }

    /**
     * Puts the app's record beside what this process did itself.
     */
    public function pull(): void
    {
        $section = Records::pull()[self::SECTION] ?? null;

        if (! is_array($section)) {
            return;
        }

        foreach (self::RECORDED as $name) {
            $remote = $section[$name] ?? [];
            self::recorded($name)->setValue($this, array_merge_recursive($this->local[$name] ?? [], is_array($remote) ? $remote : []));
        }
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
