<?php

declare(strict_types=1);

use NativePhp\Simulator\Arguments;
use NativePhp\Simulator\Command;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\DeviceCatalog;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\MobileTestFilter;
use NativePhp\Simulator\Platforms;
use NativePhp\Simulator\SuitePlan;
use NativePhp\Simulator\SuiteRegistration;
use Pest\Factories\Attribute;
use Pest\Factories\TestCaseMethodFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\SkippedWithMessageException;
use Tests\Support\FakeMachine;

afterEach(function () {
    Arguments::reset();
});

it('skips a test grouped for a platform the suite does not run', function () {
    $method = mobileMethod('ios');

    SuiteRegistration::run([
        new Device('android', 'Pixel 8', true),
    ], function () use ($method): void {
        (new MobileTestFilter)->accept($method);
    });

    expect(fn () => ($method->closure)())->toThrow(
        SkippedWithMessageException::class,
        'This test is limited to a platform the mobile suite does not run.',
    );
});

it('skips a platform this machine cannot run and keeps the other', function () {
    Platforms::use(new FakeMachine(xcrun: null, companion: null));
    $method = mobileMethod();

    SuiteRegistration::run([
        new Device('ios', 'iPhone Latest', false),
        new Device('android', 'Pixel Default', false),
    ], function () use ($method): void {
        (new MobileTestFilter)->accept($method);
    });

    expect($method->datasets[0])->toBe([
        ['android:0:Pixel Default'],
    ]);
});

it('skips when the machine has no Android SDK', function () {
    Platforms::use(new FakeMachine(sdk: null, adb: null, emulator: null));
    $method = mobileMethod('android');

    SuiteRegistration::run([
        new Device('android', 'Pixel 8', true),
    ], function () use ($method): void {
        (new MobileTestFilter)->accept($method);
    });

    expect(fn () => ($method->closure)())->toThrow(
        SkippedWithMessageException::class,
        'Android Emulator tests need the Android SDK, adb, and the emulator package.',
    );
});

it('keeps a placeholder device when the catalog cannot be read', function () {
    $devices = SuitePlan::devices(false, null, false, null, ['android'], DeviceCatalog::resolve());

    expect(array_map(fn (Device $device): string => $device->platform.':'.$device->name, $devices))->toBe([
        'ios:iPhone',
        'android:Pixel Default',
    ]);
});

it('uses the catalog when both platforms can run', function () {
    $devices = SuitePlan::devices(false, null, false, null, ['ios', 'android'], DeviceCatalog::resolve());

    expect(array_map(fn (Device $device): string => $device->name, $devices))->toBe([
        'iPhone Latest',
        'Pixel Default',
    ]);
});

it('skips an ios suite when the run asked for android', function () {
    Arguments::intercept(['--android']);
    $devices = Arguments::select([
        new Device('ios', 'iPhone 17', true),
    ]);
    $method = mobileMethod();

    SuiteRegistration::run($devices, function () use ($method): void {
        (new MobileTestFilter)->accept($method);
    });

    expect($devices)->toBe([])
        ->and(fn () => ($method->closure)())->toThrow(
            SkippedWithMessageException::class,
            'This test is limited to a platform the mobile suite does not run.',
        );
});

it('skips an ios test when the run asked for android', function () {
    Arguments::intercept(['--android']);
    $devices = Arguments::select([
        new Device('ios', 'iPhone 17', true),
        new Device('android', 'Pixel 8', true),
    ]);
    $method = mobileMethod('ios');

    SuiteRegistration::run($devices, function () use ($method): void {
        (new MobileTestFilter)->accept($method);
    });

    expect(array_map(fn (Device $device): string => $device->platform, $devices))->toBe(['android'])
        ->and(fn () => ($method->closure)())->toThrow(
            SkippedWithMessageException::class,
            'This test is limited to a platform the mobile suite does not run.',
        );
});

it('skips a platform when its devices cannot be listed', function () {
    DeviceCatalog::reset();

    $catalog = new DeviceCatalog(new class extends Command
    {
        public function run(string $binary, array $arguments, ?string $cwd = null): string
        {
            if (in_array('-list-avds', $arguments, true)) {
                throw new SimulatorException('No Android AVD is installed.');
            }

            return (string) json_encode([
                'devices' => [
                    'com.apple.CoreSimulator.SimRuntime.iOS-26-0' => [
                        ['name' => 'iPhone 17', 'udid' => 'PHONE', 'state' => 'Shutdown', 'isAvailable' => true],
                    ],
                ],
            ]);
        }
    });

    try {
        $devices = SuitePlan::devices(false, null, false, null, ['ios', 'android'], $catalog);

        expect(array_map(fn (Device $device): string => $device->platform.':'.$device->name, $devices))->toBe([
            'ios:iPhone 17',
            'android:Android',
        ])->and(Platforms::runnable())->toBe(['ios']);

        $method = mobileMethod('android');

        SuiteRegistration::run($devices, function () use ($method): void {
            (new MobileTestFilter)->accept($method);
        });

        expect(fn () => ($method->closure)())->toThrow(
            SkippedWithMessageException::class,
            'Android Emulator tests need the Android SDK, adb, and the emulator package.',
        );
    } finally {
        DeviceCatalog::fake('iPhone Latest', 'Pixel Default');
        Platforms::use(new FakeMachine);
    }
});

it('still rejects an ambiguous device name', function () {
    Arguments::intercept(['--device=Pixel']);

    Arguments::select([
        new Device('ios', 'Pixel', false),
        new Device('android', 'Pixel', false),
    ]);
})->throws(SimulatorException::class);

function mobileMethod(string ...$groups): TestCaseMethodFactory
{
    $method = new TestCaseMethodFactory(__FILE__, function (): void {});

    foreach ($groups as $group) {
        $method->attributes[] = new Attribute(Group::class, [$group]);
    }

    return $method;
}
