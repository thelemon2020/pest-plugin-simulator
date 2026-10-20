<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Illuminate\Http\Client\ResponseSequence;
use ReflectionProperty;

/**
 * A ResponseSequence from Http::sequence() or Http::fakeSequence(), as its responses and
 * what it does once they run out (see Payload). It is packed when the fakes file is
 * written, so responses the test pushed after fakeSequence() returned are in it.
 */
final class StubSequence
{
    /**
     * @param  list<mixed>  $responses  each one packed
     */
    public function __construct(
        public readonly array $responses,
        public readonly bool $failWhenEmpty,
        public readonly mixed $whenEmpty,
    ) {}

    public static function fromSequence(ResponseSequence $sequence): self
    {
        $responses = self::read($sequence, 'responses');

        return new self(
            array_values(array_map(Payload::pack(...), is_array($responses) ? $responses : [])),
            self::read($sequence, 'failWhenEmpty') !== false,
            Payload::pack(self::read($sequence, 'emptyResponse')),
        );
    }

    public function toSequence(): ResponseSequence
    {
        $sequence = new ResponseSequence(array_map(Payload::unpack(...), $this->responses));

        if ($this->whenEmpty !== null) {
            $sequence->whenEmpty(Payload::unpack($this->whenEmpty));
        } elseif (! $this->failWhenEmpty) {
            $sequence->dontFailWhenEmpty();
        }

        return $sequence;
    }

    private static function read(ResponseSequence $sequence, string $property): mixed
    {
        return (new ReflectionProperty(ResponseSequence::class, $property))->getValue($sequence);
    }
}
