<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

/**
 * A file addPhoto() puts in the device's photo library.
 */
final class Photo
{
    private const TYPES = ['jpg', 'jpeg', 'png', 'gif', 'heic', 'webp', 'mp4', 'mov'];

    /**
     * The file's full path. A relative path is read from the directory the tests run in.
     */
    public static function file(string $path): string
    {
        $full = str_starts_with($path, '/') ? $path : (getcwd() ?: '.').'/'.$path;

        if (! is_file($full)) {
            throw new SimulatorException("addPhoto() could not find [{$path}].");
        }

        $type = strtolower(pathinfo($full, PATHINFO_EXTENSION));

        if (! in_array($type, self::TYPES, true)) {
            throw new SimulatorException('addPhoto() takes an image or video ('.implode(', ', self::TYPES)."), not [{$path}].");
        }

        return $full;
    }
}
