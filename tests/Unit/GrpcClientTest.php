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

    (new Client($this->stub->url()))->streamPaced('hid', ['down', 'up', 'again'], 200_000);

    $requests = $this->stub->requests();
    $frames = $requests[0]['frames'];

    expect($requests)->toHaveCount(1)
        ->and(array_column($frames, 'bytes'))->toBe([Protobuf::frame('down'), Protobuf::frame('up'), Protobuf::frame('again')])
        ->and($frames[1]['at'] - $frames[0]['at'])->toBeGreaterThanOrEqual(0.19)
        ->and($frames[2]['at'] - $frames[1]['at'])->toBeGreaterThanOrEqual(0.19);
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

    $response = (new Client($this->stub->url(), timeoutSeconds: 0.3))->streamPaced('hid', ['a', 'b', 'c'], 250_000);

    expect($response)->toBe('done');
});

it('says when nothing is listening', function () {
    $url = 'http://127.0.0.1:'.StubCompanion::unusedPort();

    expect(fn () => (new Client($url))->unary('accessibility_info', 'request'))
        ->toThrow(SimulatorException::class, "Companion [accessibility_info] is not reachable at {$url}");
});
