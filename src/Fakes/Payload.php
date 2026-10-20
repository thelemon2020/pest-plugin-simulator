<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ResponseSequence;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Serializers\Signed;
use NativePhp\Simulator\Exceptions\SimulatorException;
use Throwable;

/**
 * The fakes file: what the test faked, written by the test and read by the app (see
 * FakeSync and Mirror). A first line names the format and the run's signing key, and a
 * serialize()d array follows.
 *
 * A closure in a fake runs in the app, so it travels as Laravel's SerializableClosure,
 * signed. The app makes its own APP_KEY (docs/spikes/laravel-parity.md), so the signature
 * is made with a key of this run's own, written beside it. The key keeps nothing secret:
 * the file is only read by a debug build outside production, from the app's own storage.
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
     * @param  array<string, mixed>  $data
     */
    public static function encode(array $data, string $key): string
    {
        return self::withKey($key, function () use ($data, $key): string {
            /** @var array<string, mixed> $packed */
            $packed = self::pack($data);
            $sections = array_diff_key($packed, ['test' => true]);

            // A hash of each fake, so the app applies again only the ones that changed. It
            // cannot serialize a signed closure itself without the key.
            $packed['hashes'] = array_map(fn (mixed $section): string => sha1(serialize($section)), $sections);

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
        $data = self::withKey($key, fn (): mixed => unserialize(substr($bytes, $newline + 1)));

        if (! is_array($data)) {
            throw new SimulatorException('The fakes file could not be read.');
        }

        /** @var array<string, mixed> $data */
        return $data;
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
