<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;
use NativePhp\Simulator\Exceptions\SimulatorException;
use Pest\Plugins\Parallel;
use PHPUnit\Event\TestSuite\Loaded;
use PHPUnit\Event\TestSuite\LoadedSubscriber;

final class ParallelLanes implements LoadedSubscriber
{
    public const string ENV = 'NATIVEPHP_SIMULATOR_LANE';

    public const string DEVICE = 'NATIVEPHP_SIMULATOR_LANE_DEVICE';

    public const string ONLY_MOBILE = 'NATIVEPHP_SIMULATOR_LANE_MOBILE';

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
        return Env::get(self::ENV) === '1';
    }

    /**
     * A later lane runs the mobile() tests for its device. The first lane runs the rest of the suite.
     */
    public static function onlyMobileTests(): bool
    {
        return Env::get(self::ONLY_MOBILE) === '1';
    }

    public static function pinned(): ?Device
    {
        $value = Env::get(self::DEVICE);

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Device::fromKey($value);
        } catch (SimulatorException) {
            return null;
        }
    }

    /**
     * @param  list<Device>  $devices
     * @return list<Device>
     */
    public static function matching(array $devices): array
    {
        $pinned = self::pinned();

        if ($pinned === null) {
            return $devices;
        }

        return array_values(array_filter(
            $devices,
            fn (Device $device): bool => $device->identity() === $pinned->identity(),
        ));
    }

    /**
     * Copy lane variables into the environment ParaTest forwards to workers.
     */
    public static function publish(): void
    {
        foreach ([self::ENV, self::DEVICE, self::ONLY_MOBILE] as $key) {
            $value = Env::get($key);

            if ($value === null || $value === '') {
                continue;
            }

            Env::set($key, $value);
        }
    }

    public static function clearEnv(): void
    {
        Env::set(self::ENV, null);
        Env::set(self::DEVICE, null);
        Env::set(self::ONLY_MOBILE, null);
    }

    /**
     * @return int|null null keeps the current parallel run
     */
    public static function exitCode(): ?int
    {
        if (self::active() || ! Parallel::isEnabled() || Parallel::isWorker()) {
            return null;
        }

        $devices = SuiteRegistration::registeredDevices();

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
        self::clearEnv();
    }

    private static function lane(Device $device, bool $onlyMobileTests): int
    {
        $env = self::environment($device, $onlyMobileTests);

        if (self::$process !== null) {
            return (self::$process)($device, $env);
        }

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
    private static function environment(Device $device, bool $onlyMobileTests): array
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
        $env[self::DEVICE] = $device->identity();
        $env[self::ONLY_MOBILE] = $onlyMobileTests ? '1' : '0';

        return $env;
    }
}
