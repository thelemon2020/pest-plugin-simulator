<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Laravel;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;
use NativePhp\Simulator\Fakes\Mirror;
use NativePhp\Simulator\Fakes\RemoteHttpFactory;

/**
 * Laravel boots this twice over: in the tests, on the host, and in the app on the device,
 * whose debug build has the plugin in its vendor directory. On the host it gives the tests
 * an Http client whose fakes reach the app. In the app it applies them (see Mirror).
 */
final class ServiceProvider extends IlluminateServiceProvider
{
    public function register(): void
    {
        if (Mirror::inside($this->app) || ! $this->app->runningUnitTests()) {
            return;
        }

        $this->app->singleton(Factory::class, fn ($app): Factory => new RemoteHttpFactory($app['events']));
    }

    public function boot(): void
    {
        Mirror::boot($this->app);

        if ($this->app->runningInConsole()) {
            $this->commands([
                SimulatorCommand::class,
            ]);
        }
    }
}
