<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Sessions;
use ReflectionClass;

/**
 * A disk the test faked with Storage::fake(), whose asserts and reads see the files the app
 * wrote to its own fake of the disk too. The app lists that disk's files in its record
 * (see Mirror), and the ones that changed since the last look are copied into this disk's
 * root, beside the files the test wrote itself.
 *
 * Its positive asserts read the record again until they pass or the timeout runs out. Its
 * negative ones read it once, after the app has taken every event it was sent.
 */
final class RemoteFilesystemAdapter extends FilesystemAdapter
{
    /** Where a fake disk keeps its files, under the app's storage directory. */
    public const DISKS = 'framework/testing/disks';

    private string $disk = '';

    /** Which of the test's fakes of the disk this is: faking it again makes another. */
    private int $generation = 0;

    private static int $generations = 0;

    /** @var array<string, string> the files last copied from the app, by path, with their hashes */
    private array $pulled = [];

    /** @var list<string> the directories last made for the app's */
    private array $directories = [];

    private bool $reading = false;

    /**
     * The disk Storage::fake() made, as this Remote one. FakeSync puts it in the fake's
     * place at the next step.
     */
    public static function from(FilesystemAdapter $fake, string $disk): self
    {
        $remote = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();

        foreach ((new ReflectionClass(FilesystemAdapter::class))->getProperties() as $property) {
            if (! $property->isStatic() && $property->isInitialized($fake)) {
                $property->setValue($remote, $property->getValue($fake));
            }
        }

        $remote->disk = $disk;
        $remote->generation = ++self::$generations;

        return $remote;
    }

    public function generation(): int
    {
        return $this->generation;
    }

    /**
     * Copies in the files the app wrote or changed since the last pull, and takes away the
     * ones it deleted. A file the test wrote itself stays.
     */
    public function pull(): void
    {
        if (! Run::inside() || FakeSync::test() === null) {
            return;
        }

        $section = Records::pull()['storage'][$this->disk] ?? null;

        if (! is_array($section) || ($section['generation'] ?? null) !== $this->generation) {
            $section = [];
        }

        $files = is_array($section['files'] ?? null) ? $section['files'] : [];
        $directories = is_array($section['directories'] ?? null) ? array_values(array_map(strval(...), $section['directories'])) : [];
        $driver = Sessions::get(Run::device());
        $root = rtrim($this->path(''), '/');

        foreach ($directories as $directory) {
            if (self::safe($directory) && ! is_dir($root.'/'.$directory)) {
                @mkdir($root.'/'.$directory, 0777, true);
            }
        }

        foreach ($files as $path => $hash) {
            $path = (string) $path;

            if (! self::safe($path) || ($this->pulled[$path] ?? null) === $hash) {
                continue;
            }

            @mkdir(dirname($root.'/'.$path), 0777, true);

            if ($driver->pullAppFile('storage/'.self::DISKS.'/'.$this->disk.'/'.$path, $root.'/'.$path)) {
                $this->pulled[$path] = (string) $hash;
            }
        }

        // What the app deleted goes, unless the test has written over it since.
        foreach (array_diff_key($this->pulled, $files) as $path => $hash) {
            $local = $root.'/'.$path;

            if (is_file($local) && md5_file($local) === $hash) {
                @unlink($local);
            }

            unset($this->pulled[$path]);
        }

        foreach (array_reverse(array_diff($this->directories, $directories)) as $directory) {
            @rmdir($root.'/'.$directory);
        }

        $this->directories = $directories;
    }

    /**
     * A path the app listed, which must stay inside the disk.
     */
    private static function safe(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! in_array('..', explode('/', $path), true);
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertExists(...$arguments)
    {
        $this->eventually(fn () => parent::assertExists(...$arguments));

        return $this;
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertCount(...$arguments)
    {
        $this->eventually(fn () => parent::assertCount(...$arguments));

        return $this;
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertMissing(...$arguments)
    {
        $this->settled(fn () => parent::assertMissing(...$arguments));

        return $this;
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertDirectoryEmpty(...$arguments)
    {
        $this->settled(fn () => parent::assertDirectoryEmpty(...$arguments));

        return $this;
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function assertEmpty(...$arguments)
    {
        $this->settled(fn () => parent::assertEmpty(...$arguments));

        return $this;
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function exists(...$arguments)
    {
        return $this->reading(fn () => parent::exists(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function missing(...$arguments)
    {
        return $this->reading(fn () => parent::missing(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function fileExists(...$arguments)
    {
        return $this->reading(fn () => parent::fileExists(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function fileMissing(...$arguments)
    {
        return $this->reading(fn () => parent::fileMissing(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function directoryExists(...$arguments)
    {
        return $this->reading(fn () => parent::directoryExists(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function directoryMissing(...$arguments)
    {
        return $this->reading(fn () => parent::directoryMissing(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function get(...$arguments)
    {
        return $this->reading(fn () => parent::get(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     * @return array<array-key, mixed>|null
     */
    public function json(...$arguments)
    {
        return $this->reading(fn () => parent::json(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function readStream(...$arguments)
    {
        return $this->reading(fn () => parent::readStream(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function size(...$arguments)
    {
        return $this->reading(fn () => parent::size(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function checksum(...$arguments)
    {
        return $this->reading(fn () => parent::checksum(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function mimeType(...$arguments)
    {
        return $this->reading(fn () => parent::mimeType(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function lastModified(...$arguments)
    {
        return $this->reading(fn () => parent::lastModified(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function files(...$arguments)
    {
        return $this->reading(fn () => parent::files(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function allFiles(...$arguments)
    {
        return $this->reading(fn () => parent::allFiles(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function directories(...$arguments)
    {
        return $this->reading(fn () => parent::directories(...$arguments));
    }

    /**
     * @param  mixed  ...$arguments
     */
    public function allDirectories(...$arguments)
    {
        return $this->reading(fn () => parent::allDirectories(...$arguments));
    }

    /**
     * @param  Closure(): mixed  $assert
     */
    private function eventually(Closure $assert): void
    {
        $this->inside(fn () => Records::eventually($this->pull(...), fn () => $assert()));
    }

    /**
     * @param  Closure(): mixed  $assert
     */
    private function settled(Closure $assert): void
    {
        $this->inside(fn () => Records::settled($this->pull(...), fn () => $assert()));
    }

    /**
     * Reads the app's files first, unless an assert or another read that has just read
     * them is calling.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    private function reading(Closure $read): mixed
    {
        if ($this->reading) {
            return $read();
        }

        return $this->inside(function () use ($read): mixed {
            $this->pull();

            return $read();
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function inside(Closure $work): mixed
    {
        if ($this->reading) {
            return $work();
        }

        $this->reading = true;

        try {
            return $work();
        } finally {
            $this->reading = false;
        }
    }
}
