<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Carbon\Carbon;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\BusFake;
use Illuminate\Support\Testing\Fakes\EventFake;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Illuminate\Support\Testing\Fakes\PendingBatchFake;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Laravel\SerializableClosure\SerializableClosure;
use Native\Mobile\Edge\Contracts\TreeObserver;
use Native\Mobile\Edge\TreeObservers;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * The app's side of the fakes file. A debug build of the app has this package in its vendor
 * directory, and Laravel boots its ServiceProvider there too, which hands the app to boot().
 *
 * It does nothing unless the app runs inside NativePHP, outside production, with APP_DEBUG
 * on, and finds the fakes file in its storage. A release build has no dev dependencies, so
 * the plugin is not in it at all; the checks keep it inert in a build that has it anyway,
 * such as one for internal testing with APP_ENV=staging. Then it calls Laravel's own fakes,
 * as the test did: fake() on the Http client, Mail::fake(), Notification::fake(),
 * Event::fake(), Queue::fake(), Bus::fake(), Storage::fake(), Carbon::setTestNow(),
 * config(). After each render it writes what the fakes recorded, and the files on each
 * fake disk, to the record file, which the test's asserts read (see Records).
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

    /** @var array<string, array{0: bool, 1: mixed}> config keys the fakes changed: whether each was there, and its value before */
    private array $config = [];

    /** @var list<string> the fingerprint of each Http fake in place, in order */
    private array $httpIds = [];

    /** @var list<ResponseSequence> the sequences the Http fakes in place answer from */
    private array $sequences = [];

    private bool $time = false;

    /** The app's own client, from before it was faked. */
    private ?Factory $http = null;

    private ?string $written = null;

    /**
     * Laravel's fakes the app can run, by record section: the facade, Laravel's fake, and
     * the arrays it records into, with how deep each one's entries sit.
     *
     * @var array<string, array{0: class-string, 1: class-string, 2: array<string, int>}>
     */
    private const FAKES = [
        'mail' => [Mail::class, MailFake::class, ['mailables' => 1, 'queuedMailables' => 1]],
        'notifications' => [Notification::class, NotificationFake::class, ['notifications' => 4]],
        'events' => [Event::class, EventFake::class, ['events' => 2]],
        'queue' => [Queue::class, QueueFake::class, ['jobs' => 2]],
        'bus' => [Bus::class, BusFake::class, ['commands' => 2, 'commandsSync' => 2, 'commandsAfterResponse' => 2, 'batches' => 1]],
    ];

    /** @var array<string, object> the app's own services the fakes stand in for */
    private array $real = [];

    /** @var array<string, int> which of the test's fakes each section's fake stands for */
    private array $generations = [];

    /** @var array<string, int> the disks faked, by name, with which of the test's fakes each stands for */
    private array $disks = [];

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
     * Forgets the app it was booted in. A real app boots once; the tests' stand-in app is
     * made again for every test.
     */
    public static function reset(): void
    {
        self::$current = null;
    }

    /**
     * Inside NativePHP, outside production, with APP_DEBUG on. The host never sets
     * NativePHP's flag.
     */
    public static function inside(Container $app): bool
    {
        $config = $app->bound('config') ? $app->make('config') : null;
        $running = ($config !== null && $config->get('nativephp-internal.running') === true)
            || ($_SERVER['NATIVEPHP_RUNNING'] ?? getenv('NATIVEPHP_RUNNING')) === 'true';

        return $running
            && $config !== null && $config->get('app.debug') === true
            && method_exists($app, 'environment') && ! $app->environment('production');
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

            // A freed inode can be used again at once, and a bool in config keeps the size,
            // so within one second a push could look the same by its stat alone.
            $seen = $stat['ino'].':'.$stat['mtime'].':'.$stat['size'].':'.md5_file($path);

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
                $records['sequences'] = array_map(fn (ResponseSequence $sequence): bool => $sequence->isEmpty(), $this->sequences);
            }

            foreach (self::FAKES as $section => [$facade, $class, $properties]) {
                $fake = $facade::getFacadeRoot();

                if (isset($this->real[$section]) && $fake instanceof $class) {
                    $records[$section] = ['generation' => $this->generations[$section] ?? 0];

                    foreach ($properties as $property => $depth) {
                        // A Laravel that renamed one leaves that part out, not the record.
                        if (! property_exists($class, $property)) {
                            continue;
                        }

                        $value = (new ReflectionProperty($class, $property))->getValue($fake);
                        $records[$section][$property] = self::serializable(is_array($value) ? $value : [], $depth);
                    }
                }
            }

            foreach ($this->disks as $disk => $generation) {
                $records['storage'][$disk] = ['generation' => $generation] + self::listing(Storage::disk($disk)->path(''));
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

        foreach (['now', 'config', 'http', ...array_keys(self::FAKES), 'storage'] as $section) {
            $value = $payload[$section] ?? null;
            $hash = is_string($hashes[$section] ?? null) ? $hashes[$section] : '';

            if (($this->applied[$section] ?? '') === $hash) {
                continue;
            }

            match ($section) {
                'now' => $this->now(is_array($value) ? $value : null),
                'config' => $this->config(is_array($value) ? $value : []),
                'http' => $this->http(is_array($value) ? $value : null),
                'storage' => $this->storage(is_array($value) ? $value : []),
                default => $this->fake($section, is_array($value) ? $value : null),
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

        $moment = Carbon::createFromFormat('U.u', (string) $now['timestamp']);

        if ($moment === null) {
            return;
        }

        Carbon::setTestNow($moment->setTimezone((string) $now['timezone']));
        $this->time = true;
    }

    /**
     * Puts back what the last config section changed, then takes away the keys the test
     * removed and sets the ones it changed.
     *
     * @param  array<array-key, mixed>  $section  `forget` and `set`, as FakeSync sends them
     */
    private function config(array $section): void
    {
        $config = $this->app->make('config');

        foreach (array_reverse($this->config, true) as $key => [$had, $value]) {
            $had ? $config->set($key, $value) : $this->unset($config, $key);
        }

        $this->config = [];

        foreach (is_array($section['forget'] ?? null) ? $section['forget'] : [] as $key) {
            $this->remember($config, (string) $key);
            $this->unset($config, (string) $key);
        }

        foreach (is_array($section['set'] ?? null) ? $section['set'] : [] as $key => $value) {
            $this->remember($config, (string) $key);
            $config->set($key, $value);
        }
    }

    private function remember(mixed $config, string $key): void
    {
        if (! array_key_exists($key, $this->config)) {
            $this->config[$key] = [$config->has($key), $config->get($key)];
        }
    }

    /**
     * Takes a key out of config, rather than setting it to null, so config()->has() says
     * what it says in the test.
     */
    private function unset(mixed $config, string $key): void
    {
        if (! $config instanceof Repository) {
            $config->set($key, null);

            return;
        }

        $items = new ReflectionProperty(Repository::class, 'items');
        $all = $items->getValue($config);

        if (is_array($all)) {
            Arr::forget($all, $key);
            $items->setValue($config, $all);
        }
    }

    /**
     * Fakes the app's client the way the test faked its own: a copy of the app's client, so
     * it keeps the app's global middleware and options, given the same fake() calls.
     *
     * A fake the test added after the app had its others is added to the client in place,
     * as Http::fake() adds to the test's, so a sequence the app has answered from goes on
     * from where it was. A fake that changed starts the client over.
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

            $this->httpIds = [];
            $this->sequences = [];

            return;
        }

        $this->http ??= $current;
        $fakes = array_values(is_array($http['fakes'] ?? null) ? $http['fakes'] : []);
        $ids = array_values(array_map(strval(...), is_array($http['ids'] ?? null) ? $http['ids'] : []));
        $faked = $current !== $this->http;
        $adds = $faked && $this->httpIds !== [] && array_slice($ids, 0, count($this->httpIds)) === $this->httpIds;
        $recorded = $faked ? $current->recorded()->values()->all() : [];

        if ($adds) {
            $fake = $current;
            $fakes = array_slice($fakes, count($this->httpIds));
        } else {
            $fake = clone $this->http;
            $this->sequences = [];
        }

        foreach ($fakes as $callback) {
            $callback = Payload::unpack($callback);
            array_push($this->sequences, ...self::sequencesIn($callback));
            $fake->fake($callback);
        }

        $fake->preventStrayRequests((bool) ($http['prevent'] ?? false));
        $allow = $http['allow'] ?? [];

        if (is_array($allow) && $allow !== [] && (new ReflectionMethod($fake, 'allowStrayRequests'))->getNumberOfParameters() > 0) {
            $fake->allowStrayRequests($allow);
        }

        // Laravel's fake() starts its record again, so a fake the test added clears what
        // the app recorded before it, as Http::fake() clears the test's. A fake the test
        // only changed, such as a sequence it pushed onto, or preventStrayRequests(),
        // keeps the record.
        if ($faked && ! ($adds && $fakes !== [])) {
            (new ReflectionProperty(Factory::class, 'recorded'))->setValue($fake, $recorded);
        }

        $this->httpIds = $ids;
        $this->bind($fake);
    }

    /**
     * @return list<ResponseSequence>
     */
    private static function sequencesIn(mixed $callback): array
    {
        if ($callback instanceof ResponseSequence) {
            return [$callback];
        }

        return is_array($callback) ? array_values(array_filter($callback, fn (mixed $value): bool => $value instanceof ResponseSequence)) : [];
    }

    /**
     * Fakes one of Mail, Notification, Event, Queue, and Bus with Laravel's own fake, as
     * the test did. Each starts from the app's own service, so a fake the test changed
     * part way is made again from it, and keeps what the one before it recorded.
     *
     * @param  array<array-key, mixed>|null  $settings
     */
    private function fake(string $section, ?array $settings): void
    {
        [$facade, $class, $properties] = self::FAKES[$section];
        $current = $facade::getFacadeRoot();

        if ($settings === null) {
            if (isset($this->real[$section])) {
                $this->put($section, $this->real[$section]);
                unset($this->real[$section], $this->generations[$section]);
            }

            return;
        }

        $this->real[$section] ??= $current;
        $this->put($section, $this->real[$section]);
        // Faking again in the test starts its record again, as Laravel's fake() does.
        $generation = (int) ($settings['generation'] ?? 0);
        $same = ($this->generations[$section] ?? null) === $generation;
        $this->generations[$section] = $generation;
        $fake = Payload::unpack($settings['fake'] ?? []);
        $fake = is_array($fake) ? $fake : [];

        $made = match ($section) {
            'mail' => Mail::fake(),
            'notifications' => Notification::fake(),
            // NativePHP's own events stay real: its screens listen for them.
            'events' => Event::fake($fake)->except([...self::list($settings['dispatch'] ?? []), self::native(...)]),
            'queue' => Queue::fake($fake)->except(self::list($settings['queue'] ?? [])),
            'bus' => Bus::fake($fake)->except(self::list($settings['dispatch'] ?? [])),
            default => throw new \LogicException("No fake for [{$section}]."),
        };

        if ($same && $current instanceof $class && $current !== $this->real[$section]) {
            foreach (array_keys($properties) as $property) {
                if (! property_exists($class, $property)) {
                    continue;
                }

                $recorded = new ReflectionProperty($class, $property);
                $recorded->setValue($made, $recorded->getValue($current));
            }
        }
    }

    /**
     * Fakes the disks the test faked, each with Storage::fake() and the config the test gave
     * it, which empties the disk. A disk
     * the test faked again is faked again, and one it no longer fakes is the app's own.
     *
     * @param  array<array-key, mixed>  $disks
     */
    private function storage(array $disks): void
    {
        foreach (array_diff_key($this->disks, $disks) as $disk => $generation) {
            Storage::forgetDisk($disk);
            unset($this->disks[$disk]);
        }

        foreach ($disks as $disk => $settings) {
            $generation = is_array($settings) ? (int) ($settings['generation'] ?? 0) : 0;

            if (($this->disks[$disk] ?? null) !== $generation) {
                $config = is_array($settings) ? Payload::unpack($settings['config'] ?? []) : [];
                $config = is_array($config) ? $config : [];
                Storage::fake((string) $disk, $config);
                $this->disks[(string) $disk] = $generation;
            }
        }
    }

    /**
     * The files on a fake disk, each with a hash of what it holds, so the test copies only
     * the ones that changed, and its directories.
     *
     * @return array{files: array<string, string>, directories: list<string>}
     */
    private static function listing(string $root): array
    {
        $root = rtrim($root, '/');
        $files = [];
        $directories = [];

        if (is_dir($root)) {
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);

            foreach ($entries as $entry) {
                $path = substr($entry->getPathname(), strlen($root) + 1);

                if ($entry->isDir()) {
                    $directories[] = $path;
                } elseif ($entry->isFile()) {
                    $files[$path] = (string) md5_file($entry->getPathname());
                }
            }
        }

        ksort($files);
        sort($directories);

        return ['files' => $files, 'directories' => $directories];
    }

    private function put(string $section, object $service): void
    {
        $facade = self::FAKES[$section][0];
        $facade::swap($service);

        if ($section === 'events' && $service instanceof Dispatcher) {
            Model::setEventDispatcher($service);

            // Event::fake() hands the cache its fake too. The app is booted once, so without
            // this its cache events would go to a fake for good.
            if ($this->app->bound('cache') && method_exists($facade = Cache::class, 'refreshEventDispatcher')) {
                $facade::refreshEventDispatcher();
            }
        }
    }

    private static function native(mixed $event): bool
    {
        return is_string($event) && str_starts_with($event, 'Native\\');
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        $value = Payload::unpack($value);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * A fake's record, each mailable, event, or job serialized on its own, so the test
     * reads each one on its own: one whose model the app has since deleted does not take
     * the rest with it. One serialize() cannot write, holding a closure or a stream, is left
     * out of the record, not the cause of an app error. Laravel's own events, with a
     * Request or a connection in them, are like that.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function serializable(array $values, int $depth): array
    {
        $kept = [];

        foreach ($values as $key => $value) {
            if ($depth > 1 && is_array($value)) {
                $kept[$key] = self::serializable($value, $depth - 1);

                continue;
            }

            try {
                $kept[$key] = [Records::ENTRY => serialize($value instanceof PendingBatchFake ? self::batch($value) : $value)];
            } catch (Throwable) {
            }
        }

        return array_is_list($values) ? array_values($kept) : $kept;
    }

    /**
     * A batch as the test reads it. It holds the app's bus, which does not serialize, so
     * the copy leaves it out. Its then(), catch(), and finally() closures are signed with
     * the app's own key, which the test cannot read, so they are left out too.
     */
    private static function batch(PendingBatchFake $batch): PendingBatchFake
    {
        $copy = clone $batch;
        (new ReflectionProperty(PendingBatchFake::class, 'bus'))->setValue($copy, null);

        $copy->options = array_map(
            fn (mixed $option): mixed => is_array($option) ? array_values(array_filter($option, fn (mixed $callback): bool => ! $callback instanceof SerializableClosure)) : $option,
            $copy->options,
        );

        return $copy;
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

        foreach (array_keys(self::FAKES) as $section) {
            $this->fake($section, null);
        }

        $this->storage([]);

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
