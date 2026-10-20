<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Carbon\Carbon;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Facade;
use Native\Mobile\Edge\Contracts\TreeObserver;
use Native\Mobile\Edge\TreeObservers;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * The app's side of the fakes file. A debug build of the app has this package in its vendor
 * directory, and Laravel boots its ServiceProvider there too, which hands the app to boot().
 *
 * It does nothing unless the app runs inside NativePHP, outside production, and finds the
 * fakes file in its storage. Then it calls Laravel's own fakes, as the test did: fake() on
 * the Http client, Carbon::setTestNow(), config(). After each render it writes what the
 * fakes recorded to the record file, which the test's asserts read (see Records).
 *
 * A NativePHP screen is one request that does not end while the screen is up, so nothing
 * that runs after a request runs between two taps. The file is checked when a route is
 * matched, before the screen's mount(), and then NativePHP's own tree observers take over:
 * before each event the screen handles, and the record is written after each render (see
 * TreeTap and docs/spikes/laravel-parity.md). Looking costs one stat when there is no
 * file. The app is booted once and kept, so a fake stays in place across requests, and the
 * real services are put back when the file goes.
 */
final class Mirror
{
    private static ?self $current = null;

    private static bool $observing = false;

    /** The stat of the file last applied: a push moves a new file into place. */
    private ?string $seen = null;

    /** The test whose fakes are in place, or null when none are. */
    private ?string $test = null;

    /** @var array<string, string> the hash of each fake, as last applied */
    private array $applied = [];

    /** @var array<string, mixed> config values from before the fakes set them */
    private array $config = [];

    private bool $time = false;

    /** The app's own client, from before it was faked. */
    private ?Factory $http = null;

    private ?string $written = null;

    private function __construct(private readonly Container $app) {}

    public static function boot(Container $app): void
    {
        if (! self::inside($app)) {
            return;
        }

        // Nothing is applied while the app boots. NativePHP boots Laravel for its artisan
        // commands too, and the first launch after an install runs config:cache: a fake
        // config value applied then was cached for good. The fakes go in when a route is
        // matched, before a screen's mount() runs, and before each event after that.
        $mirror = new self($app);

        if ($app->bound('events')) {
            $app->make('events')->listen(RouteMatched::class, function () use ($mirror): void {
                self::$current = $mirror;
                $mirror->sync();
            });
        }

        if (! self::$observing && interface_exists(TreeObserver::class) && class_exists(TreeObservers::class)) {
            TreeObservers::register(new TreeTap);
            self::$observing = true;
        }
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * Inside NativePHP, outside production. The host never sets NativePHP's flag.
     */
    public static function inside(Container $app): bool
    {
        $running = ($app->bound('config') && $app->make('config')->get('nativephp-internal.running') === true)
            || ($_SERVER['NATIVEPHP_RUNNING'] ?? getenv('NATIVEPHP_RUNNING')) === 'true';

        return $running && method_exists($app, 'environment') && ! $app->environment('production');
    }

    /**
     * Applies the fakes file when it has changed, and puts the real services back when it
     * has gone.
     */
    public function sync(): void
    {
        $this->guard(function (): void {
            $path = $this->path(Payload::FAKES);
            clearstatcache(true, $path);
            $stat = @stat($path);

            if ($stat === false) {
                $this->restore();

                return;
            }

            $seen = $stat['ino'].':'.$stat['mtime'].':'.$stat['size'];

            if ($seen === $this->seen) {
                return;
            }

            $this->seen = $seen;
            $payload = Payload::decode((string) file_get_contents($path));

            if (($payload['test'] ?? null) !== $this->test) {
                $this->restore();
                $this->test = is_string($payload['test'] ?? null) ? $payload['test'] : null;
            }

            $this->apply($payload);
        });
    }

    /**
     * Writes what the fakes recorded, when it changed since the last write.
     */
    public function record(): void
    {
        if ($this->test === null) {
            return;
        }

        $this->guard(function (): void {
            $fakes = $this->path(Payload::FAKES);
            clearstatcache(true, $fakes);

            // The test ended, and took its fakes away.
            if (! is_file($fakes)) {
                $this->restore();

                return;
            }

            $records = ['test' => $this->test];
            $client = $this->app->make(Factory::class);

            if ($this->http !== null && $client !== $this->http) {
                $records['http'] = HttpRecords::encode($client);
            }

            $bytes = serialize($records);
            $hash = sha1($bytes);

            if ($hash === $this->written) {
                return;
            }

            $path = $this->path(Payload::RECORDS);
            $temporary = $path.'.'.bin2hex(random_bytes(4));

            if (! is_dir(dirname($path))) {
                @mkdir(dirname($path), 0777, true);
            }

            if (@file_put_contents($temporary, $bytes) !== false && @rename($temporary, $path)) {
                $this->written = $hash;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function apply(array $payload): void
    {
        $hashes = is_array($payload['hashes'] ?? null) ? $payload['hashes'] : [];

        foreach (['now', 'config', 'http'] as $section) {
            $value = $payload[$section] ?? null;
            $hash = is_string($hashes[$section] ?? null) ? $hashes[$section] : '';

            if (($this->applied[$section] ?? '') === $hash) {
                continue;
            }

            match ($section) {
                'now' => $this->now(is_array($value) ? $value : null),
                'config' => $this->config(is_array($value) ? $value : []),
                'http' => $this->http(is_array($value) ? $value : null),
            };

            $this->applied[$section] = $hash;
        }
    }

    /**
     * @param  array<array-key, mixed>|null  $now
     */
    private function now(?array $now): void
    {
        if ($now === null) {
            Carbon::setTestNow();
            $this->time = false;

            return;
        }

        Carbon::setTestNow(Carbon::parse((string) $now['time'], (string) $now['timezone']));
        $this->time = true;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function config(array $values): void
    {
        $config = $this->app->make('config');

        foreach (array_diff_key($this->config, $values) as $key => $original) {
            $config->set($key, $original);
            unset($this->config[$key]);
        }

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $this->config)) {
                $this->config[$key] = $config->get($key);
            }

            $config->set($key, $value);
        }
    }

    /**
     * Fakes the app's client the way the test faked its own: a copy of the app's client, so
     * it keeps the app's global middleware and options, given the same fake() calls.
     *
     * @param  array<array-key, mixed>|null  $http
     */
    private function http(?array $http): void
    {
        $current = $this->app->make(Factory::class);

        if ($http === null) {
            if ($this->http !== null) {
                $this->bind($this->http);
                $this->http = null;
            }

            return;
        }

        $this->http ??= $current;
        $fake = clone $this->http;

        foreach (is_array($http['fakes'] ?? null) ? $http['fakes'] : [] as $callback) {
            $fake->fake(Payload::unpack($callback));
        }

        $fake->preventStrayRequests((bool) ($http['prevent'] ?? false));
        $allow = $http['allow'] ?? [];

        if (is_array($allow) && $allow !== [] && (new ReflectionMethod($fake, 'allowStrayRequests'))->getNumberOfParameters() > 0) {
            $fake->allowStrayRequests($allow);
        }

        // fake() starts its record again. A fake the test changed part way keeps what the
        // one before it recorded.
        if ($current !== $this->http) {
            (new ReflectionProperty(Factory::class, 'recorded'))->setValue($fake, $current->recorded()->values()->all());
        }

        $this->bind($fake);
    }

    private function bind(Factory $client): void
    {
        $this->app->instance(Factory::class, $client);
        Facade::clearResolvedInstance(Factory::class);
    }

    /**
     * Puts back what the fakes replaced.
     */
    private function restore(): void
    {
        if ($this->test === null && $this->applied === []) {
            return;
        }

        $this->config([]);

        if ($this->time) {
            Carbon::setTestNow();
            $this->time = false;
        }

        $this->http(null);
        $this->test = null;
        $this->applied = [];
        $this->written = null;
    }

    private function path(string $file): string
    {
        $storage = method_exists($this->app, 'storagePath') ? $this->app->storagePath() : 'storage';

        return rtrim((string) $storage, '/').'/'.$file;
    }

    /**
     * A fake that cannot be applied must not take the app down. Laravel's report() logs
     * it, and the test fails on it as an app error (see AppLog).
     *
     * @param  \Closure(): void  $work
     */
    private function guard(\Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            if (function_exists('report')) {
                report($exception);
            }
        }
    }
}
