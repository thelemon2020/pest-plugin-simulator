<?php

declare(strict_types=1);

use NativePhp\Simulator\Device;
use NativePhp\Simulator\ParallelLanes;
use NativePhp\Simulator\SuiteRegistration;
use Pest\Plugins\Parallel;

afterEach(function () {
    SuiteRegistration::forgetRegisteredDevices();
    ParallelLanes::reset();
});

it('leaves a one-device parallel run on a single lane', function () {
    $argv = $_SERVER['argv'];
    $_SERVER['argv'][] = '--parallel';
    $called = false;
    ParallelLanes::$process = function () use (&$called): int {
        $called = true;

        return 0;
    };

    try {
        SuiteRegistration::run([
            new Device('ios', 'iPhone 17', true),
        ], function (): void {});

        expect(Parallel::isEnabled())->toBeTrue()
            ->and(ParallelLanes::exitCode())->toBeNull()
            ->and($called)->toBeFalse();
    } finally {
        $_SERVER['argv'] = $argv;
    }
});

it('starts one lane per device and keeps the worst status', function () {
    $argv = $_SERVER['argv'];
    $_SERVER['argv'][] = '--parallel';
    $lanes = [];
    ParallelLanes::$process = function (Device $device, array $env) use (&$lanes): int {
        $lanes[] = [
            $device->identity(),
            $env[ParallelLanes::ENV],
            $env[ParallelLanes::DEVICE],
            $env[ParallelLanes::ONLY_MOBILE],
        ];

        return $device->platform === 'android' ? 2 : 0;
    };

    try {
        SuiteRegistration::run([
            new Device('ios', 'iPhone 17', true),
            new Device('ios', 'iPhone 17', true),
        ], function (): void {});
        SuiteRegistration::run([
            new Device('android', 'Pixel 8', true),
        ], function (): void {});

        expect(ParallelLanes::exitCode())->toBe(2)
            ->and($lanes)->toBe([
                ['ios:iPhone 17', '1', 'ios:iPhone 17', '0'],
                ['android:Pixel 8', '1', 'android:Pixel 8', '1'],
            ])
            ->and(ParallelLanes::command()[0])->toBe(PHP_BINARY)
            ->and(ParallelLanes::command())->toContain('--parallel');
    } finally {
        $_SERVER['argv'] = $argv;
    }
});

it('stays in the current run when this process is already a lane', function () {
    $argv = $_SERVER['argv'];
    $_SERVER['argv'][] = '--parallel';
    $_ENV[ParallelLanes::ENV] = '1';
    $_SERVER[ParallelLanes::ENV] = '1';
    putenv(ParallelLanes::ENV.'=1');

    try {
        SuiteRegistration::run([
            new Device('ios', 'iPhone 17', true),
            new Device('android', 'Pixel 8', true),
        ], function (): void {});

        expect(ParallelLanes::exitCode())->toBeNull();
    } finally {
        $_SERVER['argv'] = $argv;
    }
});

it('stays in the current run inside a paratest worker', function () {
    $argv = $_SERVER['argv'];
    $paratest = $_SERVER['PARATEST'] ?? null;
    $_SERVER['argv'][] = '--parallel';
    $_SERVER['PARATEST'] = '1';

    try {
        SuiteRegistration::run([
            new Device('ios', 'iPhone 17', true),
            new Device('android', 'Pixel 8', true),
        ], function (): void {});

        expect(Parallel::isWorker())->toBeTrue()
            ->and(ParallelLanes::exitCode())->toBeNull();
    } finally {
        $_SERVER['argv'] = $argv;

        if ($paratest === null) {
            unset($_SERVER['PARATEST']);
        } else {
            $_SERVER['PARATEST'] = $paratest;
        }
    }
});
