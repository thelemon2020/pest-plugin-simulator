<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Grpc\Protobuf;

final class Hid
{
    /** @var array<string, array{0: int, 1: bool}> */
    private const KEYS = [
        'a' => [4, false], 'b' => [5, false], 'c' => [6, false], 'd' => [7, false],
        'e' => [8, false], 'f' => [9, false], 'g' => [10, false], 'h' => [11, false],
        'i' => [12, false], 'j' => [13, false], 'k' => [14, false], 'l' => [15, false],
        'm' => [16, false], 'n' => [17, false], 'o' => [18, false], 'p' => [19, false],
        'q' => [20, false], 'r' => [21, false], 's' => [22, false], 't' => [23, false],
        'u' => [24, false], 'v' => [25, false], 'w' => [26, false], 'x' => [27, false],
        'y' => [28, false], 'z' => [29, false],
        'A' => [4, true], 'B' => [5, true], 'C' => [6, true], 'D' => [7, true],
        'E' => [8, true], 'F' => [9, true], 'G' => [10, true], 'H' => [11, true],
        'I' => [12, true], 'J' => [13, true], 'K' => [14, true], 'L' => [15, true],
        'M' => [16, true], 'N' => [17, true], 'O' => [18, true], 'P' => [19, true],
        'Q' => [20, true], 'R' => [21, true], 'S' => [22, true], 'T' => [23, true],
        'U' => [24, true], 'V' => [25, true], 'W' => [26, true], 'X' => [27, true],
        'Y' => [28, true], 'Z' => [29, true],
        '1' => [30, false], '2' => [31, false], '3' => [32, false], '4' => [33, false],
        '5' => [34, false], '6' => [35, false], '7' => [36, false], '8' => [37, false],
        '9' => [38, false], '0' => [39, false],
        ' ' => [44, false], '-' => [45, false], '=' => [46, false], '.' => [55, false],
        ',' => [54, false], '/' => [56, false], '@' => [31, true],
    ];

    public static function point(float $x, float $y): string
    {
        return Protobuf::doubleField(1, $x).Protobuf::doubleField(2, $y);
    }

    /**
     * @return list<string>
     */
    public static function tap(float $x, float $y): array
    {
        $touch = Protobuf::messageField(1, self::point($x, $y));
        $pressAction = Protobuf::messageField(1, $touch);
        $down = Protobuf::messageField(1, Protobuf::messageField(1, $pressAction));
        $up = Protobuf::messageField(1, Protobuf::messageField(1, $pressAction).Protobuf::varintField(2, 1));

        return [$down, $up];
    }

    /**
     * @return list<string>
     */
    public static function text(string $text): array
    {
        $events = [];

        foreach (str_split($text) as $character) {
            $key = self::KEYS[$character] ?? throw new SimulatorException("No key for [{$character}].");
            $events = array_merge($events, self::key($key[0], $key[1]));
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    private static function key(int $keycode, bool $shift): array
    {
        $press = Protobuf::messageField(1, Protobuf::messageField(3, Protobuf::varintField(1, $keycode)));
        $down = Protobuf::messageField(1, $press);
        $up = Protobuf::messageField(1, $press.Protobuf::varintField(2, 1));

        if (! $shift) {
            return [$down, $up];
        }

        $shiftPress = Protobuf::messageField(1, Protobuf::messageField(3, Protobuf::varintField(1, 225)));
        $shiftDown = Protobuf::messageField(1, $shiftPress.Protobuf::varintField(2, 0));
        $shiftUp = Protobuf::messageField(1, $shiftPress.Protobuf::varintField(2, 1));

        return [$shiftDown, $down, $up, $shiftUp];
    }

    public static function accessibilityInfo(): string
    {
        return Protobuf::varintField(3, 2).Protobuf::varintField(8, 2);
    }
}
