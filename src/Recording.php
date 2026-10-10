<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;
use Pest\TestSuite;
use PHPUnit\Framework\TestCase;

final class Recording
{
    private static bool $wanted = false;

    private static ?string $path = null;

    private static bool $started = false;

    /** The clip was started for record_failures, not by record(), so a pass deletes it. */
    private static bool $automatic = false;

    /** Where record() asked a clip record_failures started to be written. */
    private static ?string $moveTo = null;

    /** @var list<string> clips record_failures kept until the test's outcome is known */
    private static array $pending = [];

    /**
     * Record this test from its first screen(), and keep the clip only if the test fails.
     */
    public static function everyTest(): void
    {
        if (self::$started) {
            return;
        }

        self::$wanted = true;
        self::$path = null;
        self::$automatic = true;
    }

    /**
     * A record() during a clip record_failures started takes that clip over: it keeps
     * going, it is kept when the test passes, and a $path given here is where it ends up.
     */
    public static function request(?string $path): void
    {
        if (self::$started) {
            if (self::$automatic) {
                self::$automatic = false;
                self::$moveTo = $path;
            }

            return;
        }

        self::$wanted = true;
        self::$path = $path;
        self::$automatic = false;
    }

    public static function begin(Driver $driver, Device $device): void
    {
        if (! self::$wanted || self::$started) {
            return;
        }

        $path = self::$path ?? self::destination($device);
        self::ensureDirectory($path);
        $driver->startRecording($path);
        self::$path = $path;
        self::$started = true;
    }

    public static function stop(?Driver $driver = null): void
    {
        if (! self::$started) {
            throw new SimulatorException('No recording is in progress.');
        }

        self::finish($driver);
    }

    public static function finish(?Driver $driver = null): void
    {
        if (! self::$started) {
            self::reset();

            return;
        }

        try {
            ($driver ?? Sessions::get(Run::device()))->stopRecording();
            self::file();
        } finally {
            self::reset();
        }
    }

    /**
     * The test passed: delete the clips record_failures made for it.
     */
    public static function passed(): void
    {
        foreach (self::$pending as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        self::$pending = [];
    }

    /**
     * The test is over. Whatever passed() did not delete is kept.
     */
    public static function finished(): void
    {
        self::$pending = [];
    }

    /**
     * Forgets the clip under way. Clips waiting on the test's outcome stay until PHPUnit
     * says how it ended, which comes after the test's own afterEach().
     */
    public static function reset(): void
    {
        self::$wanted = false;
        self::$path = null;
        self::$started = false;
        self::$automatic = false;
        self::$moveTo = null;
    }

    private static function file(): void
    {
        if (self::$path === null) {
            return;
        }

        if (self::$automatic) {
            self::$pending[] = self::$path;

            return;
        }

        if (self::$moveTo !== null && self::$moveTo !== self::$path && is_file(self::$path)) {
            self::ensureDirectory(self::$moveTo);
            rename(self::$path, self::$moveTo);
        }
    }

    private static function destination(Device $device): string
    {
        $root = getcwd();

        return ($root === false ? '.' : $root)
            .'/simulator-recordings/'
            .self::label()
            .'-'
            .self::slug($device->platform.'-'.$device->name)
            .'.mp4';
    }

    private static function label(): string
    {
        $current = TestSuite::getInstance()->test;
        $name = $current instanceof TestCase ? $current->name() : 'recording';
        $name = preg_replace('/^__pest_evaluable_/', '', $name) ?? $name;
        $name = preg_replace('/^it_/', '', $name) ?? $name;

        return self::slug(substr(self::slug($name), 0, 80));
    }

    private static function slug(string $value): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-'));

        return $slug === '' ? 'recording' : $slug;
    }

    private static function ensureDirectory(string $path): void
    {
        $directory = dirname($path);

        if ($directory !== '' && $directory !== '.' && ! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
    }
}
