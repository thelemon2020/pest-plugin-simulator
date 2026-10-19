<?php

declare(strict_types=1);

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Exceptions\CompanionUnresponsive;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Grpc\Client;
use NativePhp\Simulator\Grpc\Protobuf;
use NativePhp\Simulator\Hid;
use NativePhp\Simulator\IosDriver;
use Tests\Support\RecordingClient;

afterEach(function () {
    Configuration::reset();
});

it('swipes for a given duration and holds a press', function () {
    $client = new RecordingClient;
    $driver = iosInputDriver($client);

    $driver->swipe(10, 20, 30, 40, 0.15);
    $driver->press(50, 60, 0.8);

    expect($client->calls[0])->toBe(['stream', 'hid', Hid::swipe(10, 20, 30, 40, 0.15), 0.15])
        ->and($client->calls[0][2][0])->toContain("\x31".pack('e', 0.15))
        ->and($client->calls[1])->toBe(['streamPaced', 'hid', Hid::tap(50, 60), 800_000]);
});

it('waits for a tapped field to finish taking focus before the first key', function () {
    $tree = fn (array $elements): string => (string) json_encode(['elements' => $elements]);
    $field = ['type' => 'TextField', 'label' => 'Name', 'frame' => ['x' => 20, 'y' => 320, 'width' => 360, 'height' => 30]];
    $title = ['type' => 'StaticText', 'label' => 'Add a shelf', 'frame' => ['x' => 20, 'y' => 100, 'width' => 200, 'height' => 20]];
    $client = new RecordingClient;
    $client->trees = [$tree([$field]), $tree([$title, $field])];
    $driver = iosInputDriver($client);

    $driver->text('Ta');

    expect(array_map(fn (array $call): string => $call[0].' '.$call[1], $client->calls))->toBe([
        'unary accessibility_info',
        'unary accessibility_info',
        'unary accessibility_info',
        'streamStrokes hid',
        'unary accessibility_info',
    ]);
});

it('types anyway when the tree cannot be read', function () {
    $client = new RecordingClient;
    $driver = iosInputDriver($client);

    $driver->text('Ta');

    expect(array_map(fn (array $call): string => $call[0].' '.$call[1], $client->calls))->toBe([
        'unary accessibility_info',
        'streamStrokes hid',
        'unary accessibility_info',
    ]);
});

it('waits for focus and the keyboard only for the first text after a touch', function () {
    $client = new RecordingClient;
    $driver = iosInputDriver($client);

    $driver->text('T');
    $driver->text('a');
    $driver->tap(100, 200);
    $driver->text('b');

    expect(array_map(fn (array $call): string => $call[0].' '.$call[1], $client->calls))->toBe([
        'unary accessibility_info',
        'streamStrokes hid',
        'unary accessibility_info',
        'streamStrokes hid',
        'streamPaced hid',
        'unary accessibility_info',
        'streamStrokes hid',
        'unary accessibility_info',
    ]);
});

it('parks a software keyboard left on screen after typing', function () {
    $client = new RecordingClient;
    $client->trees = [keyboardTree(617), keyboardTree(617), keyboardTree(617), keyboardTree(925)];
    $driver = iosInputDriver($client);

    $driver->text('Ta');

    expect(array_map(fn (array $call): string => $call[0].' '.$call[1], $client->calls))->toBe([
        'unary accessibility_info',
        'unary accessibility_info',
        'streamStrokes hid',
        'unary accessibility_info',
        'unary setting',
        'unary accessibility_info',
    ])->and($client->calls[4][2])->toBe([Hid::hardwareKeyboard()]);
});

it('leaves a keyboard parked below the display alone', function () {
    $client = new RecordingClient;
    $client->trees = [keyboardTree(925)];
    $driver = iosInputDriver($client);

    $driver->text('Ta');

    expect(array_column($client->calls, 1))->not->toContain('setting');
});

it('stops at one try when the companion cannot connect a hardware keyboard', function () {
    $client = new RecordingClient;
    $client->trees = [keyboardTree(617)];
    $client->failing = ['setting'];
    $driver = iosInputDriver($client);

    $driver->text('Ta');

    expect(array_map(fn (array $call): string => $call[0].' '.$call[1], $client->calls))->toBe([
        'unary accessibility_info',
        'unary accessibility_info',
        'streamStrokes hid',
        'unary accessibility_info',
        'unary setting',
    ]);
});

it('encodes a slower swipe than the default', function () {
    expect(Hid::swipe(1, 2, 3, 4, 0.6)[0])->toContain("\x31".pack('e', 0.6))
        ->and(Hid::swipe(1, 2, 3, 4)[0])->toContain("\x31".pack('e', 0.3))
        ->and(Hid::swipe(1, 2, 3, 4, 0.6)[0])->not->toBe(Hid::swipe(1, 2, 3, 4)[0]);
});

it('lifts the touch when a tap fails between its down and up', function () {
    $client = failingClient(new SimulatorException('Companion [hid] failed: Recv failure'));
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve());
    (new ReflectionProperty(IosDriver::class, 'client'))->setValue($driver, $client);

    expect(fn () => $driver->tap(50, 60))->toThrow(SimulatorException::class, 'Recv failure')
        ->and($client->streamed)->toBe([[Hid::tap(50, 60)[1]]]);
});

it('lets go of every key and Shift when typing fails part-way', function () {
    $client = failingClient(new SimulatorException('Companion [hid] failed: Recv failure'));
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve());
    (new ReflectionProperty(IosDriver::class, 'client'))->setValue($driver, $client);

    expect(fn () => $driver->text('aB'))->toThrow(SimulatorException::class, 'Recv failure')
        ->and($client->streamed)->toBe([Hid::releases(Hid::keystrokes('aB'))])
        ->and($client->streamed[0])->toHaveCount(3);
});

it('does not wait on a companion that stopped answering to let go', function () {
    $client = failingClient(new CompanionUnresponsive('Companion [hid] did not answer in time'));
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve());
    (new ReflectionProperty(IosDriver::class, 'client'))->setValue($driver, $client);

    expect(fn () => $driver->tap(50, 60))->toThrow(CompanionUnresponsive::class)
        ->and(fn () => $driver->text('a'))->toThrow(CompanionUnresponsive::class)
        ->and($client->streamed)->toBe([]);
});

it('types each key as a stroke of its own', function () {
    $client = new RecordingClient;
    $driver = iosInputDriver($client);

    $driver->text('aB');

    $sends = array_values(array_filter($client->calls, fn (array $call): bool => $call[0] !== 'unary'));

    expect($sends)->toBe([['streamStrokes', 'hid', Hid::keystrokes('aB'), 20_000]]);
});

it('waits the gap between keys after a symbol before the next key', function () {
    $client = timedClient();
    $driver = iosInputDriver($client);

    $driver->text('a@e');

    expect(array_column($client->sends, 0))->toBe(['streamStrokes', 'stream', 'streamStrokes'])
        ->and($client->sends[1][1])->toBe(Hid::text('@'))
        ->and($client->sends[2][1])->toBe(Hid::keystrokes('e'))
        ->and(($client->sends[2][2] - $client->sends[1][2]) / 1e6)->toBeGreaterThanOrEqual(20.0);
});

it('reads until the reader in the simulator has started, before the app is built', function (array $answers, int $reads) {
    $client = scriptedReads($answers);
    $driver = iosInputDriver($client);

    (new ReflectionMethod(IosDriver::class, 'warmUpReader'))->invoke($driver);

    expect($client->reads)->toBe($reads);
})->with([
    'an answer at once' => [[null], 1],
    'two reads out of time, then an answer' => [[
        new CompanionUnresponsive('Companion [accessibility_info] did not answer in time: Operation timed out after 15002 milliseconds with 0 bytes received'),
        new SimulatorException('Companion [accessibility_info] failed: The axbridge guest reader failed: timed out connecting to the serve socket'),
        null,
    ], 3],
    'an error from a reader that is up' => [[
        new SimulatorException('Companion [accessibility_info] failed: axbridge could not resolve the frontmost application'),
        null,
    ], 1],
    'no companion to reach' => [[
        new CompanionUnresponsive('Companion [accessibility_info] is not reachable at http://127.0.0.1:10882: Failed to connect'),
        null,
    ], 1],
]);

it('allows a back gesture its own duration on top of the call timeout', function () {
    $client = new RecordingClient;
    $driver = iosInputDriver($client);

    $driver->back();

    expect($client->calls[0][3] ?? null)->toBe(0.6);
});

it('keeps the home indicator clear only on an iPhone with no home button', function (float $width, float $height, float $strip) {
    $client = new RecordingClient;
    $client->trees = [(string) json_encode(['elements' => [
        ['type' => 'Application', 'label' => 'App', 'frame' => ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height]],
    ]])];
    $driver = iosInputDriver($client);

    $driver->describe();

    expect($driver->viewport())->toBe([$width, $height])
        ->and($driver->homeIndicator())->toBe($strip);
})->with([
    'iPhone 17 Pro' => [402.0, 874.0, 34.0],
    'iPhone SE' => [375.0, 667.0, 0.0],
    'iPhone 17 Pro on its side' => [874.0, 402.0, 0.0],
    'iPad Air' => [820.0, 1180.0, 0.0],
]);

it('gives a companion call as long as a screen step, within bounds', function (float $step, float $call) {
    Configuration::configure(['timeout' => $step]);
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve());

    $client = (new ReflectionMethod(IosDriver::class, 'connect'))->invoke($driver, 10882);

    expect((new ReflectionProperty(Client::class, 'timeoutSeconds'))->getValue($client))->toBe($call);
})->with([
    'the configured step' => [15.0, 15.0],
    'no less than a slow read needs' => [2.0, 10.0],
    'no more than the client allows' => [45.0, 30.0],
]);

/**
 * An iPhone 17 Pro screen with a focused field and the software keyboard's top row at $keysY:
 * 617 is on the glass, 925 is parked below the 874-point display.
 */
function keyboardTree(float $keysY): string
{
    return (string) json_encode(['elements' => [
        ['type' => 'Application', 'label' => 'App', 'frame' => ['x' => 0, 'y' => 0, 'width' => 402, 'height' => 874]],
        ['type' => 'TextField', 'label' => 'Name', 'frame' => ['x' => 20, 'y' => 160, 'width' => 360, 'height' => 30]],
        ['type' => 'Key', 'label' => 'q', 'frame' => ['x' => 6, 'y' => $keysY - 21, 'width' => 36, 'height' => 42]],
        ['type' => 'Key', 'label' => 'w', 'frame' => ['x' => 46, 'y' => $keysY - 21, 'width' => 36, 'height' => 42]],
    ]]);
}

function iosInputDriver(Client $client): IosDriver
{
    $driver = new IosDriver(new Device('ios', 'iPhone', true), Configuration::resolve());
    (new ReflectionProperty(IosDriver::class, 'client'))->setValue($driver, $client);

    return $driver;
}

/**
 * A client that reads a screen with no keyboard on it, and keeps each key it is sent with
 * when it was sent, in nanoseconds.
 */
function timedClient(): Client
{
    return new class extends Client
    {
        /** @var list<array{0: string, 1: list<string>|list<list<string>>, 2: int}> */
        public array $sends = [];

        public function __construct()
        {
            parent::__construct('http://127.0.0.1:0');
        }

        public function unary(string $method, string $message): string
        {
            return $method === 'accessibility_info' ? Protobuf::messageField(1, (string) json_encode(['elements' => []])) : '';
        }

        public function stream(string $method, array $messages, float $durationSeconds = 0.0): string
        {
            $this->sends[] = ['stream', $messages, hrtime(true)];

            return '';
        }

        public function streamStrokes(string $method, array $strokes, int $gapMicroseconds): string
        {
            $this->sends[] = ['streamStrokes', $strokes, hrtime(true)];

            return '';
        }
    };
}

/**
 * A client whose reads fail or answer in turn: an exception fails that read, and null
 * answers it with an empty screen. The last one repeats.
 *
 * @param  list<SimulatorException|null>  $answers
 */
function scriptedReads(array $answers): Client
{
    return new class($answers) extends Client
    {
        public int $reads = 0;

        /**
         * @param  list<SimulatorException|null>  $answers
         */
        public function __construct(private readonly array $answers)
        {
            parent::__construct('http://127.0.0.1:0');
        }

        public function unary(string $method, string $message): string
        {
            $answer = $this->answers[min($this->reads, count($this->answers) - 1)];
            $this->reads++;

            if ($answer !== null) {
                throw $answer;
            }

            return Protobuf::messageField(1, (string) json_encode(['elements' => []]));
        }
    };
}

/**
 * A client whose paced calls fail with $failure, and which keeps every plain stream sent.
 */
function failingClient(SimulatorException $failure): Client
{
    return new class($failure) extends Client
    {
        /** @var list<list<string>> */
        public array $streamed = [];

        public function __construct(private readonly SimulatorException $failure)
        {
            parent::__construct('http://127.0.0.1:0');
        }

        public function stream(string $method, array $messages, float $durationSeconds = 0.0): string
        {
            $this->streamed[] = $messages;

            return '';
        }

        public function streamPaced(string $method, array $messages, int $gapMicroseconds): string
        {
            throw $this->failure;
        }

        public function streamStrokes(string $method, array $strokes, int $gapMicroseconds): string
        {
            throw $this->failure;
        }
    };
}
