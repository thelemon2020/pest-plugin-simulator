<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use NativePhp\Simulator\Command;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\RunningCommand;

final class RecordingCommand extends Command
{
    /** @var list<array{0: string, 1: list<string>}> */
    public array $calls = [];

    /** @var array<string, float> keyed by the command line */
    public array $timeouts = [];

    /** @var array<string, string> */
    public array $failures = [];

    /** @var array<string, string> */
    public array $outputs = [];

    public string $output = '';

    /** @var (Closure(string, list<string>): ?string)|null */
    public ?Closure $responder = null;

    public ?Closure $afterStart = null;

    public ?Closure $afterStop = null;

    public bool $alive = true;

    public bool $exited = true;

    /** @var list<array{0: int, 1: float}> pid and seconds, one per wait() */
    public array $waited = [];

    public function __construct(private readonly string $container = '') {}

    public function run(string $binary, array $arguments, ?string $cwd = null, ?float $timeout = null): string
    {
        $this->calls[] = [$binary, $arguments];
        $this->timeouts[implode(' ', [$binary, ...$arguments])] = (float) ($timeout ?? Command::limit($arguments));

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

    public function toFile(string $binary, array $arguments, string $path, ?float $timeout = null): void
    {
        file_put_contents($path, $this->run($binary, $arguments, null, $timeout));
    }

    /**
     * What open() hands back. Null, the default, is a command that could not start, so an
     * AndroidDriver reads with `uiautomator dump`.
     */
    public ?RunningCommand $opened = null;

    public function open(string $binary, array $arguments): ?RunningCommand
    {
        $this->calls[] = [$binary, $arguments];

        return $this->opened;
    }

    public function start(string $binary, array $arguments, string $log): int
    {
        $this->calls[] = [$binary, $arguments];

        // afterStart stands for the companion coming up. What starts through xcrun, a
        // recording or the location warm-up, is not it.
        if ($this->afterStart !== null && $binary !== 'xcrun') {
            ($this->afterStart)();
        }

        return 4242;
    }

    public function stop(int $pid): void
    {
        $this->calls[] = ['kill', [(string) $pid]];

        if ($this->afterStop !== null) {
            ($this->afterStop)();
        }
    }

    public function interrupt(int $pid): void
    {
        $this->calls[] = ['interrupt', [(string) $pid]];
    }

    public function running(int $pid): bool
    {
        return $this->alive;
    }

    public function wait(int $pid, float $seconds = 10): bool
    {
        $this->waited[] = [$pid, $seconds];

        return $this->exited;
    }
}
