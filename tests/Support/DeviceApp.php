<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use NativePhp\Simulator\Fakes\Mirror;
use Orchestra\Testbench\Foundation\Application as Testbench;

/**
 * A second Laravel app in the same process, standing in for the one on the device: its
 * storage is a directory of its own, and FakeDriver's app files are copied in and out of
 * it, as a push and a pull would. run() points the facades at it while app code runs, and
 * back at the test's app after.
 */
final class DeviceApp
{
    public readonly string $root;

    private readonly Application $app;

    public function __construct(private readonly FakeDriver $driver)
    {
        $this->root = sys_get_temp_dir().'/simulator-device-app-'.uniqid('', true);
        mkdir($this->root.'/storage/logs', 0777, true);
        $host = Container::getInstance();

        try {
            $app = Testbench::create(options: ['enables_package_discoveries' => false]);
            $app->useStoragePath($this->root.'/storage');
            $app['config']->set('nativephp-internal.running', true);
            $app['config']->set('logging.default', 'single');
            $app['config']->set('logging.channels.single.path', $this->root.'/storage/logs/laravel.log');
            $this->app = $app;
        } finally {
            self::point($host);
        }
    }

    /**
     * Runs app code in this app: the facades, the container, and the app's fakes are its
     * own while it runs. The fakes file goes in first, and the record file comes out after.
     *
     * @template T
     *
     * @param  Closure(Application): T  $work
     * @return T
     */
    public function run(Closure $work): mixed
    {
        $this->copyIn();
        $host = Container::getInstance();
        self::point($this->app);

        try {
            return $work($this->app);
        } finally {
            self::point($host);
            $this->copyOut();
        }
    }

    /**
     * The app boots, as a launch does: Mirror applies the fakes file.
     */
    public function boot(): void
    {
        $this->run(fn (Application $app) => Mirror::boot($app));
    }

    /**
     * A tap: the app checks the fakes file before it handles the event, runs the handler,
     * renders, and writes its record.
     *
     * @template T
     *
     * @param  Closure(Application): T  $handler
     * @return T
     */
    public function tap(Closure $handler): mixed
    {
        return $this->run(function (Application $app) use ($handler): mixed {
            Mirror::current()?->sync();

            try {
                return $handler($app);
            } finally {
                Mirror::current()?->record();
            }
        });
    }

    public function remove(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    private function copyIn(): void
    {
        foreach (['storage/framework/pest-simulator/fakes'] as $relative) {
            $path = $this->root.'/'.$relative;

            if (array_key_exists($relative, $this->driver->appFiles)) {
                @mkdir(dirname($path), 0777, true);
                // A push moves a new file into place.
                file_put_contents($path.'.new', $this->driver->appFiles[$relative]);
                rename($path.'.new', $path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function copyOut(): void
    {
        foreach (['storage/framework/pest-simulator/recorded', 'storage/logs/laravel.log'] as $relative) {
            $path = $this->root.'/'.$relative;

            if (is_file($path)) {
                $this->driver->appFiles[$relative] = (string) file_get_contents($path);
            }
        }
    }

    private static function point(Container $app): void
    {
        Container::setInstance($app);
        Facade::clearResolvedInstances();

        if ($app instanceof Application) {
            Facade::setFacadeApplication($app);
        }
    }
}
