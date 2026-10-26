<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Pest\Exceptions\InvalidPestCommand;
use Pest\TestSuite;
use PHPUnit\Framework\TestCase;

/**
 * The running test's name as a file name: a recording and a screenshot baseline are named
 * after the test they belong to.
 */
final class TestLabel
{
    public static function current(string $fallback): string
    {
        // Outside a Pest run, as in codegen, there is no suite to ask, and asking throws.
        try {
            $current = TestSuite::getInstance()->test;
        } catch (InvalidPestCommand) {
            $current = null;
        }

        $name = $current instanceof TestCase ? $current->name() : $fallback;
        $name = preg_replace('/^__pest_evaluable_/', '', $name) ?? $name;
        $name = preg_replace('/^it_/', '', $name) ?? $name;

        return self::slug(substr(self::slug($name), 0, 80), $fallback);
    }

    public static function slug(string $value, string $fallback = 'test'): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-'));

        return $slug === '' ? $fallback : $slug;
    }
}
