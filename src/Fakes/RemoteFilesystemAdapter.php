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
     * @param  string|array<int, string>  $path
     */
    public function assertExists($path, $content = null)
    {
        $this->eventually(fn () => parent::assertExists($path, $content));

        return $this;
    }

    public function assertCount($path, $count, $recursive = false)
    {
        $this->eventually(fn () => parent::assertCount($path, $count, $recursive));

        return $this;
    }

    /**
     * @param  string|array<int, string>  $path
     */
    public function assertMissing($path)
    {
        $this->settled(fn () => parent::assertMissing($path));

        return $this;
    }

    public function assertDirectoryEmpty($path)
    {
        $this->settled(fn () => parent::assertDirectoryEmpty($path));

        return $this;
    }

    public function assertEmpty()
    {
        $this->settled(fn () => parent::assertEmpty());

        return $this;
    }

    public function exists($path)
    {
        return $this->reading(fn () => parent::exists($path));
    }

    public function missing($path)
    {
        return $this->reading(fn () => parent::missing($path));
    }

    public function fileExists($path)
    {
        return $this->reading(fn () => parent::fileExists($path));
    }

    public function fileMissing($path)
    {
        return $this->reading(fn () => parent::fileMissing($path));
    }

    public function directoryExists($path)
    {
        return $this->reading(fn () => parent::directoryExists($path));
    }

    public function directoryMissing($path)
    {
        return $this->reading(fn () => parent::directoryMissing($path));
    }

    public function get($path)
    {
        return $this->reading(fn () => parent::get($path));
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function json($path, $flags = 0)
    {
        return $this->reading(fn () => parent::json($path, $flags));
    }

    public function readStream($path)
    {
        return $this->reading(fn () => parent::readStream($path));
    }

    public function size($path)
    {
        return $this->reading(fn () => parent::size($path));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function checksum(string $path, array $options = [])
    {
        return $this->reading(fn () => parent::checksum($path, $options));
    }

    public function mimeType($path)
    {
        return $this->reading(fn () => parent::mimeType($path));
    }

    public function lastModified($path)
    {
        return $this->reading(fn () => parent::lastModified($path));
    }

    public function files($directory = null, $recursive = false)
    {
        return $this->reading(fn () => parent::files($directory, $recursive));
    }

    public function allFiles($directory = null)
    {
        return $this->reading(fn () => parent::allFiles($directory));
    }

    public function directories($directory = null, $recursive = false)
    {
        return $this->reading(fn () => parent::directories($directory, $recursive));
    }

    public function allDirectories($directory = null)
    {
        return $this->reading(fn () => parent::allDirectories($directory));
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
