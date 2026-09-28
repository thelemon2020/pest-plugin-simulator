<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Laravel;

use Illuminate\Console\Command;
use NativePhp\Simulator\Doctor;

final class SimulatorCommand extends Command
{
    protected $signature = 'nativephp:simulator {action=doctor : The action to run (doctor)}';

    protected $description = 'Check that this machine can run NativePHP simulator tests';

    public function handle(): int
    {
        if ($this->argument('action') !== 'doctor') {
            $this->error('Unknown action. Use `php artisan nativephp:simulator doctor`.');

            return 1;
        }

        $result = Doctor::check();
        $this->output->write($result->render());

        return $result->successful() ? 0 : 1;
    }
}
