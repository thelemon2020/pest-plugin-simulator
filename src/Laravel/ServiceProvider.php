<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Laravel;

use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;

final class ServiceProvider extends IlluminateServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SimulatorCommand::class,
            ]);
        }
    }
}
