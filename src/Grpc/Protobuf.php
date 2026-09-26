<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Grpc;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class Protobuf
{
    public static function varint(int $value): string
    {
        $bytes = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;

            if ($value > 0) {
                $byte |= 0x80;
            }

            $bytes .= chr($byte);
        } while ($value > 0);

        return $bytes;
    }

    public static function field(int $number, int $wire, string $payload): string
    {
        return self::varint(($number << 3) | $wire).$payload;
    }

    public static function varintField(int $number, int $value): string
    {
        return self::field($number, 0, self::varint($value));
    }

    public static function doubleField(int $number, float $value): string
    {
        return self::field($number, 1, pack('e', $value));
    }

    public static function messageField(int $number, string $message): string
    {
        return self::field($number, 2, self::varint(strlen($message)).$message);
    }

    public static function frame(string $message): string
    {
        return chr(0).pack('N', strlen($message)).$message;
    }

    public static function stringField(string $message, int $number): string
    {
        $offset = 0;
        $length = strlen($message);

        while ($offset < $length) {
            $key = self::readVarint($message, $offset);
            $field = $key >> 3;
            $wire = $key & 0x07;

            if ($wire === 2) {
                $size = self::readVarint($message, $offset);
                $value = substr($message, $offset, $size);
                $offset += $size;

                if ($field === $number) {
                    return $value;
                }

                continue;
            }

            if ($wire === 0) {
                self::readVarint($message, $offset);

                continue;
            }

            if ($wire === 1) {
                $offset += 8;

                continue;
            }

            if ($wire === 5) {
                $offset += 4;

                continue;
            }

            throw new SimulatorException('Could not read the companion response.');
        }

        return '';
    }

    private static function readVarint(string $message, int &$offset): int
    {
        $value = 0;
        $shift = 0;

        do {
            if ($offset >= strlen($message)) {
                throw new SimulatorException('Could not read the companion response.');
            }

            $byte = ord($message[$offset]);
            $offset++;
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while (($byte & 0x80) !== 0);

        return $value;
    }
}
