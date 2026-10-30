<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Laravel;

use Illuminate\Console\Command;
use NativePhp\Simulator\Codegen\Server;
use NativePhp\Simulator\Codegen\Session;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\DeviceCatalog;
use NativePhp\Simulator\Doctor;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Shutdown;

final class SimulatorCommand extends Command
{
    protected $signature = 'nativephp:simulator
        {action=doctor : The action to run (doctor or codegen)}
        {path=/ : For codegen, the screen to start on}
        {--ios : For codegen, record on the iOS Simulator}
        {--android : For codegen, record on the Android emulator}
        {--device= : For codegen, the Simulator or AVD to record on}
        {--port=8899 : For codegen, the port the page is served on}';

    protected $description = 'Check that this machine can run NativePHP simulator tests, or record a test by clicking through the app';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'doctor' => $this->doctor(),
            'codegen' => $this->codegen(),
            default => $this->unknown(),
        };
    }

    private function doctor(): int
    {
        $result = Doctor::check();
        $this->output->write($result->render());

        return $result->successful() ? 0 : 1;
    }

    private function codegen(): int
    {
        try {
            $port = $this->port();
            $device = $this->device();
            $path = self::text($this->argument('path')) ?? '/';
            $this->info("Opening {$path} on {$device->name}…");
            $session = Session::start($device, $path);
        } catch (SimulatorException $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        try {
            (new Server($session))->listen($port, function (string $url) use ($session): void {
                $this->info("Record the test at {$url}");
                $this->line('Press Ctrl+C to stop.');

                // Ctrl+C is the way out, so the test so far is printed on the way.
                Shutdown::defer(function () use ($session): void {
                    $this->newLine();
                    $this->line($session->code());
                });
            });
        } catch (SimulatorException $exception) {
            $this->error($exception->getMessage());

            return 1;
        }
    }

    private function port(): int
    {
        $port = self::text($this->option('port')) ?? '';

        if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new SimulatorException("--port takes a port from 1 to 65535, not [{$port}].");
        }

        return (int) $port;
    }

    private function device(): Device
    {
        $requested = self::text($this->option('device')) ?? '';
        $platform = null;

        if (preg_match('/^(ios|android):(.+)$/', $requested, $prefixed)) {
            [, $platform, $requested] = $prefixed;
        }

        $platform ??= match (true) {
            $this->option('ios') && ! $this->option('android') => 'ios',
            $this->option('android') && ! $this->option('ios') => 'android',
            default => throw new SimulatorException('Pass --ios or --android to choose where to record.'),
        };

        if ($requested !== '') {
            return new Device($platform, $requested, true);
        }

        $catalog = DeviceCatalog::resolve();

        return new Device($platform, $platform === 'ios' ? $catalog->latestIphone() : $catalog->defaultAvd(), false);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function unknown(): int
    {
        $this->error('Unknown action. Use `php artisan nativephp:simulator doctor` or `php artisan nativephp:simulator codegen`.');

        return 1;
    }
}
