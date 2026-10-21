<?php

declare(strict_types=1);

namespace Tests\Support;

use NativePhp\Simulator\Laravel\ServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * A test that boots Laravel on the host, with the plugin's ServiceProvider, as an app's
 * own tests do.
 */
abstract class LaravelTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }
}
