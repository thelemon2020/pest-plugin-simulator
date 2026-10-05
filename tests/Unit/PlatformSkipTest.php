<?php

declare(strict_types=1);

use NativePhp\Simulator\Arguments;
use NativePhp\Simulator\Command;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\DeviceCatalog;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\MobileTestFilter;
use NativePhp\Simulator\ParallelLanes;
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

it('gives a dataset value to the test after the device key', function () {
    $seen = null;
    $method = new TestCaseMethodFactory(__FILE__, function (string $name) use (&$seen): void {
        $seen = [$name, \NativePhp\Simulator\Run::device()->name];
    });
    $method->datasets[] = [['Ada']];

    SuiteRegistration::run([
        new Device('ios', 'iPhone 17', true),
    ], function () use ($method): void {
        (new MobileTestFilter)->accept($method);
    });

    ($method->closure)('ios:1:iPhone 17', 'Ada');

    expect($method->datasets[0])->toBe([['ios:1:iPhone 17']])
        ->and($method->datasets[1])->toBe([['Ada']])
        ->and($seen)->toBe(['Ada', 'iPhone 17']);
});

it('drops a test outside mobile() on a later device lane', function () {
    $_ENV[ParallelLanes::FOLLOW] = '1';
    $_SERVER[ParallelLanes::FOLLOW] = '1';
    putenv(ParallelLanes::FOLLOW.'=1');

    expect((new MobileTestFilter)->accept(mobileMethod()))->toBeFalse();
});

it('keeps a mobile test on a later device lane', function () {
    $_ENV[ParallelLanes::FOLLOW] = '1';
    $_SERVER[ParallelLanes::FOLLOW] = '1';
    putenv(ParallelLanes::FOLLOW.'=1');
    $method = mobileMethod();
    $accepted = false;

    SuiteRegistration::run([
        new Device('ios', 'iPhone 17', true),
    ], function () use ($method, &$accepted): void {
        $accepted = (new MobileTestFilter)->accept($method);
    });

    expect($accepted)->toBeTrue();
});

it('skips an ios suite on an android lane the run asked for', function () {
    Arguments::intercept(['--android']);
    $_ENV[ParallelLanes::DEVICE] = 'android:Pixel 8';
    $_SERVER[ParallelLanes::DEVICE] = 'android:Pixel 8';
    putenv(ParallelLanes::DEVICE.'=android:Pixel 8');
    $method = mobileMethod();

    SuiteRegistration::run(Arguments::select([
        new Device('ios', 'iPhone 17', true),
    ]), function () use ($method): void {
        (new MobileTestFilter)->accept($method);
    });

    expect(fn () => ($method->closure)())->toThrow(
        SkippedWithMessageException::class,
        'This test is limited to a platform the mobile suite does not run.',
    );
});

it('omits a mobile test whose suite does not include the lane device', function () {
    $_ENV[ParallelLanes::DEVICE] = 'android:Pixel 8';
    $_SERVER[ParallelLanes::DEVICE] = 'android:Pixel 8';
    putenv(ParallelLanes::DEVICE.'=android:Pixel 8');
    $method = mobileMethod();
    $accepted = true;

    SuiteRegistration::run(Arguments::select([
        new Device('ios', 'iPhone 17', true),
    ]), function () use ($method, &$accepted): void {
        $accepted = (new MobileTestFilter)->accept($method);
    });

    expect($accepted)->toBeFalse();
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
