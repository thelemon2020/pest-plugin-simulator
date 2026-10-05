<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;
use Pest\Plugins\Parallel;
use PHPUnit\Event\TestSuite\Loaded;
use PHPUnit\Event\TestSuite\LoadedSubscriber;

final class ParallelLanes implements LoadedSubscriber
{
    public const string ENV = 'NATIVEPHP_SIMULATOR_LANE';

    public const string DEVICE = 'NATIVEPHP_SIMULATOR_LANE_DEVICE';

    public const string FOLLOW = 'NATIVEPHP_SIMULATOR_LANE_FOLLOW';

    /** @var (Closure(Device, array<string, string>): int)|null */
    public static ?Closure $process = null;

    public function notify(Loaded $event): void
    {
        $code = self::exitCode();

        if ($code !== null) {
            exit($code);
        }
    }

    public static function active(): bool
    {
        return self::value(self::ENV) === '1';
    }

    public static function followUp(): bool
    {
        return self::value(self::FOLLOW) === '1';
    }

    public static function pinned(): ?string
    {
        $value = self::value(self::DEVICE);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Copy lane variables into the environment ParaTest forwards to workers.
     */
    public static function publish(): void
    {
        foreach ([self::ENV, self::DEVICE, self::FOLLOW] as $key) {
            $value = self::value($key);

            if (! is_string($value) || $value === '') {
                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * @return int|null null keeps the current parallel run
     */
    public static function exitCode(): ?int
    {
        if (self::active() || ! Parallel::isEnabled() || Parallel::isWorker()) {
            return null;
        }

        $devices = SuiteRegistration::seen();

        if (count($devices) < 2) {
            return null;
        }

        return self::run($devices);
    }

    /**
     * @param  list<Device>  $devices
     */
    public static function run(array $devices): int
    {
        $worst = 0;

        foreach ($devices as $index => $device) {
            $code = self::lane($device, $index > 0);

            if ($code > $worst) {
                $worst = $code;
            }
        }

        return $worst;
    }

    /**
     * @return list<string>
     */
    public static function command(): array
    {
        $arguments = array_values(array_filter(
            $_SERVER['argv'] ?? [],
            fn (mixed $argument): bool => is_string($argument) && $argument !== '',
        ));

        if ($arguments === []) {
            $arguments = ['vendor/bin/pest'];
        }

        return [PHP_BINARY, ...$arguments];
    }

    public static function reset(): void
    {
        self::$process = null;
        self::clear(self::ENV);
        self::clear(self::DEVICE);
        self::clear(self::FOLLOW);
    }

    private static function lane(Device $device, bool $followUp): int
    {
        $pin = $device->platform.':'.$device->name;
        $env = self::environment($pin, $followUp);

        if (self::$process !== null) {
            return (self::$process)($device, $env);
        }

        fwrite(STDOUT, "Parallel lane {$pin}\n");

        $process = proc_open(self::command(), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, $env);

        if (! is_resource($process)) {
            return 1;
        }

        $code = proc_close($process);

        return $code > 0 ? $code : ($code === 0 ? 0 : 1);
    }

    /**
     * @return array<string, string>
     */
    private static function environment(string $pin, bool $followUp): array
    {
        $env = [];
        $current = getenv();

        if (is_array($current)) {
            foreach ($current as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $env[$key] = $value;
                }
            }
        }

        $env[self::ENV] = '1';
        $env[self::DEVICE] = $pin;
        $env[self::FOLLOW] = $followUp ? '1' : '0';

        return $env;
    }

    private static function value(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? $value : null;
    }

    private static function clear(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}
