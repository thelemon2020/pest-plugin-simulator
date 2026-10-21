<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ResponseSequence;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Serializers\Signed;
use NativePhp\Simulator\Exceptions\SimulatorException;
use ReflectionFunction;
use Throwable;

/**
 * The fakes file: what the test faked, written by the test and read by the app (see
 * FakeSync and Mirror). A first line names the format and the run's signing key, and a
 * serialize()d array follows.
 *
 * A closure in a fake runs in the app, so it travels as Laravel's SerializableClosure,
 * which has to be signed to be read back. The app makes its own APP_KEY
 * (docs/spikes/laravel-parity.md), so the signature is made with a key of this run's own,
 * written beside it. That key protects nothing: whoever can write the file can sign it.
 * What keeps the file safe is who reads it. Only an app inside NativePHP, outside
 * production, with APP_DEBUG on reads it (see Mirror::inside()), and only the classes this
 * file is made of are unserialized from it.
 *
 * A response from Http::response() is a promise of a PSR-7 response, whose body is a stream
 * that does not serialize, so it travels as its status, headers, and body instead.
 */
final class Payload
{
    /** The fakes file, under the app's storage directory. */
    public const FAKES = 'framework/pest-simulator/fakes';

    /** The record file the app writes back, under the app's storage directory. */
    public const RECORDS = 'framework/pest-simulator/recorded';

    private const HEADER = 'pest-simulator-fakes 1 ';

    /**
     * What the fakes file is made of, and all unserialize() makes from it. A closure's own
     * code sits inside its Signed wrapper, which checks the signature before it reads it,
     * so the class that runs that code is not one of these.
     */
    private const CLASSES = [SerializableClosure::class, Signed::class, StubResponse::class, StubSequence::class];

    /**
     * @param  array<string, mixed>  $data
     */
    public static function encode(array $data, string $key): string
    {
        return self::withKey($key, function () use ($data, $key): string {
            /** @var array<string, mixed> $packed */
            $packed = self::pack($data);

            // A hash of each fake, so the app applies again only the ones that changed.
            $packed['hashes'] = array_map(self::fingerprint(...), array_diff_key($data, ['test' => true]));

            return self::HEADER.$key."\n".serialize($packed);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $bytes): array
    {
        $newline = strpos($bytes, "\n");

        if (! str_starts_with($bytes, self::HEADER) || $newline === false) {
            throw new SimulatorException('The fakes file is not one this version of the plugin wrote.');
        }

        $key = substr($bytes, strlen(self::HEADER), $newline - strlen(self::HEADER));
        $data = self::withKey($key, fn (): mixed => unserialize(substr($bytes, $newline + 1), ['allowed_classes' => self::CLASSES]));

        if (! is_array($data)) {
            throw new SimulatorException('The fakes file could not be read.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * A hash of what a fake is, the same each time it is taken while the fake has not
     * changed. A signed closure serializes differently every time, so a closure is known
     * by the object it is and where it was written instead.
     */
    public static function fingerprint(mixed $value): string
    {
        return sha1(serialize(self::shape($value)));
    }

    private static function shape(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Closure => 'closure '.spl_object_id($value).' '.(new ReflectionFunction($value))->getFileName().':'.(new ReflectionFunction($value))->getStartLine(),
            $value instanceof PromiseInterface => StubResponse::fromPromise($value),
            $value instanceof ResponseSequence => ['sequence', spl_object_id($value), ...array_map(self::shape(...), StubSequence::parts($value))],
            is_array($value) => array_map(self::shape(...), $value),
            default => $value,
        };
    }

    /**
     * A value ready for serialize(): closures signed, responses as their parts.
     */
    public static function pack(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Closure => new SerializableClosure(self::unbound($value)),
            $value instanceof PromiseInterface => StubResponse::fromPromise($value),
            $value instanceof ResponseSequence => StubSequence::fromSequence($value),
            is_array($value) => array_map(self::pack(...), $value),
            default => $value,
        };
    }

    /**
     * A closure in a Pest test belongs to the test's class, which the app does not have, so
     * it could not be unserialized there. Out of the class, it runs anywhere, as long as it
     * does not use $this or self.
     */
    private static function unbound(Closure $closure): Closure
    {
        $unbound = @Closure::bind($closure, null, null);

        if ($unbound === null) {
            throw new SimulatorException('A closure given to a fake runs in the app, so it cannot use $this. Pass what it needs with use() instead.');
        }

        return $unbound;
    }

    /**
     * pack() undone in the app: closures, promises of responses, and sequences again.
     */
    public static function unpack(mixed $value): mixed
    {
        return match (true) {
            $value instanceof SerializableClosure => $value->getClosure(),
            $value instanceof StubResponse => $value->toPromise(),
            $value instanceof StubSequence => $value->toSequence(),
            is_array($value) => array_map(self::unpack(...), $value),
            default => $value,
        };
    }

    /**
     * A key for this run's closures: random, and the same for every file the run writes.
     */
    public static function key(): string
    {
        static $key = null;

        return $key ??= bin2hex(random_bytes(32));
    }

    /**
     * Runs $work with SerializableClosure signing by $key, and puts the app's own signer
     * back after.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private static function withKey(string $key, Closure $work): mixed
    {
        $previous = Signed::$signer;
        SerializableClosure::setSecretKey($key);

        try {
            return $work();
        } catch (Throwable $exception) {
            throw new SimulatorException('Could not pass the fakes to the app: '.$exception->getMessage(), previous: $exception);
        } finally {
            Signed::$signer = $previous;
        }
    }
}
