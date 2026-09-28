<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use NativePhp\Simulator\Command;
use NativePhp\Simulator\Exceptions\SimulatorException;

final class RecordingCommand extends Command
{
    /** @var list<array{0: string, 1: list<string>}> */
    public array $calls = [];

    /** @var array<string, string> */
    public array $failures = [];

    /** @var array<string, string> */
    public array $outputs = [];

    public string $output = '';

    /** @var (Closure(string, list<string>): ?string)|null */
    public ?Closure $responder = null;

    public ?Closure $afterStart = null;

    public function __construct(private readonly string $container = '') {}

    public function run(string $binary, array $arguments, ?string $cwd = null): string
    {
        $this->calls[] = [$binary, $arguments];

        if ($this->responder !== null) {
            $response = ($this->responder)($binary, $arguments);

            if ($response !== null) {
                return $response;
            }
        }

        foreach ($this->failures as $token => $message) {
            if (in_array($token, $arguments, true)) {
                throw new SimulatorException($message);
            }
        }

        foreach ($this->outputs as $token => $body) {
            if (in_array($token, $arguments, true)) {
                return $body;
            }
        }

        if ($this->output !== '') {
            return $this->output;
        }

        if (in_array('get_app_container', $arguments, true)) {
            return $this->container."\n";
        }

        return '';
    }

    public function start(string $binary, array $arguments, string $log): int
    {
        $this->calls[] = [$binary, $arguments];

        if ($this->afterStart !== null) {
            ($this->afterStart)();
        }

        return 4242;
    }

    public function stop(int $pid): void
    {
        $this->calls[] = ['kill', [(string) $pid]];
    }
}
