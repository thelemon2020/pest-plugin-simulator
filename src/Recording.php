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

    public static function request(?string $path): void
    {
        if (self::$started) {
            return;
        }

        self::$wanted = true;
        self::$path = $path;
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
        } finally {
            self::reset();
        }
    }

    public static function reset(): void
    {
        self::$wanted = false;
        self::$path = null;
        self::$started = false;
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
