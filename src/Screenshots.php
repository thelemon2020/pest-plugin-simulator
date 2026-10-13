<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use GdImage;
use NativePhp\Simulator\Exceptions\SimulatorException;

/**
 * Baseline screenshots, and how far a new one is from its baseline.
 */
final class Screenshots
{
    public const DIRECTORY = 'tests/.pest/simulator-screenshots';

    /**
     * How far apart two pixels' colors can be, in any channel out of 255, and still count as
     * the same: text antialiasing and image compression move colors a little between two
     * screenshots of the same screen.
     */
    private const CHANNEL_TOLERANCE = 24;

    /**
     * The baseline for this test on this device. A name tells two baselines in one test
     * apart.
     */
    public static function baseline(Device $device, ?string $name = null): string
    {
        $root = getcwd() ?: '.';
        $suffix = $name === null || $name === '' ? '' : '-'.TestLabel::slug($name, 'screenshot');

        return $root.'/'.self::DIRECTORY.'/'.TestLabel::current('screenshot').'-'.TestLabel::slug($device->platform.'-'.$device->name, 'device').$suffix.'.png';
    }

    /**
     * The share of pixels that differ, from 0 to 1, and an image of the actual screenshot
     * with them in red. Both are compared at half size, which also smooths over the noise
     * a single pixel of antialiasing makes. Screenshots of different sizes differ entirely.
     *
     * @return array{0: float, 1: GdImage|null}
     */
    public static function compare(string $expected, string $actual): array
    {
        if (! extension_loaded('gd')) {
            throw new SimulatorException('assertScreenshotMatches() needs the gd extension.');
        }

        $before = self::load($expected);
        $after = self::load($actual);
        $width = imagesx($after);
        $height = imagesy($after);

        if (imagesx($before) !== $width || imagesy($before) !== $height) {
            return [1.0, null];
        }

        $smallWidth = max(1, intdiv($width, 2));
        $smallHeight = max(1, intdiv($height, 2));
        $left = self::shrink($before, $smallWidth, $smallHeight);
        $right = self::shrink($after, $smallWidth, $smallHeight);
        $marked = self::shrink($after, $smallWidth, $smallHeight);
        $red = (int) imagecolorallocate($marked, 255, 0, 0);
        $different = 0;

        for ($y = 0; $y < $smallHeight; $y++) {
            for ($x = 0; $x < $smallWidth; $x++) {
                $a = imagecolorat($left, $x, $y);
                $b = imagecolorat($right, $x, $y);

                if ($a === $b) {
                    continue;
                }

                if (abs((($a >> 16) & 0xFF) - (($b >> 16) & 0xFF)) > self::CHANNEL_TOLERANCE
                    || abs((($a >> 8) & 0xFF) - (($b >> 8) & 0xFF)) > self::CHANNEL_TOLERANCE
                    || abs(($a & 0xFF) - ($b & 0xFF)) > self::CHANNEL_TOLERANCE) {
                    $different++;
                    imagesetpixel($marked, $x, $y, $red);
                }
            }
        }

        return [$different / ($smallWidth * $smallHeight), $marked];
    }

    private static function load(string $path): GdImage
    {
        $image = @imagecreatefrompng($path);

        if (! $image instanceof GdImage) {
            throw new SimulatorException("Could not read the screenshot [{$path}].");
        }

        return $image;
    }

    /**
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     */
    private static function shrink(GdImage $image, int $width, int $height): GdImage
    {
        $small = imagecreatetruecolor($width, $height);

        if (! $small instanceof GdImage) {
            throw new SimulatorException('Could not make an image to compare screenshots.');
        }

        imagecopyresampled($small, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $small;
    }
}
