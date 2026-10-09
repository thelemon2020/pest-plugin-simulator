<?php

declare(strict_types=1);

use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Grpc\Client;
use NativePhp\Simulator\Grpc\Protobuf;
use Tests\Support\StubCompanion;

afterEach(function () {
    if (isset($this->stub)) {
        $this->stub->stop();
    }
});

it('sends a unary request as one framed message and returns the first message back', function () {
    $this->stub = StubCompanion::start([['messages' => ['tree', 'ignored']]]);

    $response = (new Client($this->stub->url()))->unary('accessibility_info', 'request');

    expect($response)->toBe('tree')
        ->and($this->stub->requests()[0]['body'])->toBe(Protobuf::frame('request'));
});

it('sends every message of a stream in one request body', function () {
    $this->stub = StubCompanion::start([['messages' => []]]);

    $response = (new Client($this->stub->url()))->stream('hid', ['down', 'up']);

    expect($response)->toBe('')
        ->and($this->stub->requests())->toHaveCount(1)
        ->and($this->stub->requests()[0]['body'])->toBe(Protobuf::frame('down').Protobuf::frame('up'));
});

it('puts a real gap between paced messages on the wire, inside one request', function () {
    $this->stub = StubCompanion::start([['messages' => []]]);

    (new Client($this->stub->url()))->streamPaced('hid', ['down', 'up', 'again'], 300_000);

    $requests = $this->stub->requests();
    $frames = $requests[0]['frames'];

    // The stub stamps a frame when it gets round to reading it, so a stub that is slow
    // to wake on a busy runner shortens the measured gap. Half the gap still tells a
    // paced send from one that sent every frame at once.
    expect($requests)->toHaveCount(1)
        ->and(array_column($frames, 'bytes'))->toBe([Protobuf::frame('down'), Protobuf::frame('up'), Protobuf::frame('again')])
        ->and($frames[1]['at'] - $frames[0]['at'])->toBeGreaterThanOrEqual(0.15)
        ->and($frames[2]['at'] - $frames[1]['at'])->toBeGreaterThanOrEqual(0.15);
});

it('reuses one connection across calls', function () {
    $this->stub = StubCompanion::start([['messages' => ['ok']]]);
    $client = new Client($this->stub->url());

    $client->unary('accessibility_info', 'one');
    $client->stream('hid', ['two']);
    $client->streamPaced('hid', ['three', 'four'], 1_000);

    expect(array_column($this->stub->requests(), 'connection'))->toBe([1, 1, 1]);
});

it('throws the grpc-message a failed call ends with', function () {
    $this->stub = StubCompanion::start([['status' => 2, 'message' => 'window-server frontmost returned no application%20object']]);

    expect(fn () => (new Client($this->stub->url()))->unary('accessibility_info', 'request'))
        ->toThrow(SimulatorException::class, 'Companion [accessibility_info] failed: window-server frontmost returned no application object');
});

it('throws a failure that comes back with no response body at all', function () {
    $this->stub = StubCompanion::start([['trailersOnly' => true, 'status' => 5, 'message' => 'No such target']]);

    expect(fn () => (new Client($this->stub->url()))->stream('hid', ['down']))
        ->toThrow(SimulatorException::class, 'Companion [hid] failed: No such target');
});

it('gives up on a companion that never answers', function () {
    $this->stub = StubCompanion::start([['hang' => true]]);
    $started = microtime(true);

    expect(fn () => (new Client($this->stub->url(), timeoutSeconds: 0.5))->unary('accessibility_info', 'request'))
        ->toThrow(SimulatorException::class, 'Companion [accessibility_info] did not answer in time')
        ->and(microtime(true) - $started)->toBeLessThan(3.0);
});

it('opens a fresh connection after a call times out', function () {
    $this->stub = StubCompanion::start([['hang' => true], ['messages' => ['tree']]]);
    $client = new Client($this->stub->url(), timeoutSeconds: 0.5);

    try {
        $client->unary('accessibility_info', 'request');
    } catch (SimulatorException) {
    }

    expect($client->unary('accessibility_info', 'request'))->toBe('tree')
        ->and(array_column($this->stub->requests(), 'connection'))->toBe([1, 2]);
});

it('does not count the paced gaps against the timeout', function () {
    $this->stub = StubCompanion::start([['messages' => ['done']]]);

    $response = (new Client($this->stub->url(), timeoutSeconds: 1.0))->streamPaced('hid', ['a', 'b', 'c'], 600_000);

    expect($response)->toBe('done');
});

it('says when nothing is listening', function () {
    $url = 'http://127.0.0.1:'.StubCompanion::unusedPort();

    expect(fn () => (new Client($url))->unary('accessibility_info', 'request'))
        ->toThrow(SimulatorException::class, "Companion [accessibility_info] is not reachable at {$url}");
});

it('says what status came back when there is no grpc-status at all', function () {
    $this->stub = StubCompanion::start([['httpStatus' => 503]]);

    expect(fn () => (new Client($this->stub->url()))->unary('accessibility_info', 'request'))
        ->toThrow(SimulatorException::class, 'Companion [accessibility_info] failed: HTTP 503.');
});

it('tries once more on a fresh connection when a reused one dies before any answer', function () {
    $this->stub = StubCompanion::start([['messages' => ['first']], ['close' => true], ['messages' => ['second']]]);
    $client = new Client($this->stub->url());

    $first = $client->unary('accessibility_info', 'one');
    $second = $client->unary('accessibility_info', 'two');

    $connections = array_column($this->stub->requests(), 'connection');

    // Some libcurl builds open a connection of their own to replay the request before
    // finding they can't rewind it, so the retry's connection number isn't fixed.
    expect([$first, $second])->toBe(['first', 'second'])
        ->and($connections)->toHaveCount(3)
        ->and($connections[1])->toBe($connections[0])
        ->and($connections[2])->toBeGreaterThan($connections[1]);
});

it('does not try again when a fresh connection dies', function () {
    $this->stub = StubCompanion::start([['close' => true], ['messages' => ['never']]]);

    expect(fn () => (new Client($this->stub->url()))->unary('accessibility_info', 'request'))
        ->toThrow(SimulatorException::class, 'Companion [accessibility_info] failed')
        ->and($this->stub->requests())->toHaveCount(1);
});

it('keeps the whole gap when a signal interrupts it', function () {
    $this->stub = StubCompanion::start([['messages' => []]]);
    $previous = pcntl_signal_get_handler(SIGUSR1);
    $async = pcntl_async_signals(true);
    pcntl_signal(SIGUSR1, function (): void {});
    $killer = signalSoon('USR1', 0.1);

    try {
        (new Client($this->stub->url()))->streamPaced('hid', ['down', 'up'], 400_000);
    } finally {
        proc_close($killer);
        pcntl_signal(SIGUSR1, $previous);
        pcntl_async_signals($async);
    }

    $frames = $this->stub->requests()[0]['frames'];

    expect($frames[1]['at'] - $frames[0]['at'])->toBeGreaterThanOrEqual(0.3);
})->skip(! function_exists('pcntl_signal'), 'Needs the pcntl extension.');

it('holds SIGTERM back until the last paced frame is sent', function () {
    $this->stub = StubCompanion::start([['messages' => []]]);
    $previous = pcntl_signal_get_handler(SIGTERM);
    $async = pcntl_async_signals(true);
    $handled = null;
    pcntl_signal(SIGTERM, function () use (&$handled): void {
        $handled ??= microtime(true);
    });
    $started = microtime(true);
    $killer = signalSoon('TERM', 0.1);

    try {
        (new Client($this->stub->url()))->streamPaced('hid', ['down', 'up'], 400_000);
    } finally {
        proc_close($killer);
        pcntl_signal(SIGTERM, $previous);
        pcntl_async_signals($async);
    }

    // Unheld, the handler would run about 0.1s in, between the down and the up.
    expect($handled)->not->toBeNull()
        ->and($handled - $started)->toBeGreaterThanOrEqual(0.35);
})->skip(! function_exists('pcntl_sigprocmask'), 'Needs the pcntl extension.');

/**
 * Sends this process the named signal after a delay, from a child process.
 *
 * @return resource
 */
function signalSoon(string $signal, float $afterSeconds)
{
    $process = proc_open(['sh', '-c', sprintf('sleep %s; kill -%s %d', $afterSeconds, $signal, getmypid())], [], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not schedule the signal.');
    }

    return $process;
}
