<?php

declare(strict_types=1);

namespace Tests\Support;

use NativePhp\Simulator\Command;
use NativePhp\Simulator\Exceptions\SimulatorException;

final class RecordingCommand extends Command
{
    /** @var list<array{0: string, 1: list<string>}> */
    public array $calls = [];

    /** @var array<string, string> */
    public array $failures = [];

    public string $output = '';

    public function __construct(private readonly string $container = '') {}

    public function run(string $binary, array $arguments, ?string $cwd = null): string
    {
        $this->calls[] = [$binary, $arguments];

        foreach ($this->failures as $token => $message) {
            if (in_array($token, $arguments, true)) {
                throw new SimulatorException($message);
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

        return 4242;
    }

    public function stop(int $pid): void
    {
        $this->calls[] = ['kill', [(string) $pid]];
    }
}
