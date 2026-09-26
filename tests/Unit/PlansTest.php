<?php

declare(strict_types=1);

use NativePhp\Simulator\AccessibilityTree;
use NativePhp\Simulator\AvdList;
use NativePhp\Simulator\BootPlan;
use NativePhp\Simulator\DevicePlan;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Grpc\Protobuf;
use NativePhp\Simulator\SimulatorList;

it('picks the iphone on the newest runtime', function () {
    $json = json_encode([
        'devices' => [
            'com.apple.CoreSimulator.SimRuntime.iOS-18-0' => [
                ['name' => 'iPhone 16', 'udid' => 'OLD', 'state' => 'Shutdown', 'isAvailable' => true],
            ],
            'com.apple.CoreSimulator.SimRuntime.iOS-26-5' => [
                ['name' => 'iPhone 17', 'udid' => 'NEW', 'state' => 'Shutdown', 'isAvailable' => true],
                ['name' => 'iPhone 17 Pro', 'udid' => 'PRO', 'state' => 'Booted', 'isAvailable' => true],
                ['name' => 'iPad Air', 'udid' => 'PAD', 'state' => 'Shutdown', 'isAvailable' => true],
            ],
        ],
    ]);

    expect(SimulatorList::latestIphone($json))->toBe('iPhone 17 Pro')
        ->and(SimulatorList::booted($json))->toBe([['name' => 'iPhone 17 Pro', 'udid' => 'PRO']]);
});

it('fails when two unnamed devices are booted', function () {
    BootPlan::shutdowns(false, 'iPhone 17 Pro', ['iPhone 17 Pro', 'iPad Air']);
})->throws(SimulatorException::class);

it('shuts down the other named device', function () {
    expect(BootPlan::shutdowns(true, 'iPad Air', ['iPhone 17 Pro']))->toBe(['iPhone 17 Pro'])
        ->and(BootPlan::shouldBoot(true, 'iPad Air', ['iPhone 17 Pro']))->toBeTrue();
});

it('defaults to one device on each platform', function () {
    $devices = DevicePlan::resolve(false, null, false, null, 'iPhone Latest', 'Pixel Default');

    expect($devices)->toHaveCount(2)
        ->and($devices[0]->platform)->toBe('ios')
        ->and($devices[0]->named)->toBeFalse()
        ->and($devices[1]->name)->toBe('Pixel Default');
});

it('keeps only the named platform', function () {
    $devices = DevicePlan::resolve(true, ['iPhone 17 Pro', 'iPad Air'], false, null, 'iPhone Latest', 'Pixel Default');

    expect(array_map(fn ($device) => $device->name, $devices))->toBe(['iPhone 17 Pro', 'iPad Air']);
});

it('reads booted emulator serials', function () {
    expect(AvdList::booted("List of devices attached\nemulator-5554\tdevice\n"))->toBe(['emulator-5554']);
});

it('reads an accessibility frame and an android bounds', function () {
    $ios = AccessibilityTree::summarize(json_encode([
        'AXLabel' => 'Discover',
        'AXRole' => 'Button',
        'AXFrame' => '{{10, 20}, {30, 40}}',
        'children' => [],
    ]));

    $android = AccessibilityTree::summarize(json_encode([
        ['text' => 'Discover', 'class' => 'android.widget.Button', 'bounds' => '[10,20][40,60]', 'resource-id' => 'discover'],
    ]));

    expect($ios[0]['center'])->toBe([25.0, 40.0])
        ->and($android[0]['center'])->toBe([25.0, 40.0])
        ->and($android[0]['role'])->toBe('Button')
        ->and($android[0]['id'])->toBe('discover');
});

it('frames a grpc message', function () {
    $frame = Protobuf::frame('hi');

    expect($frame)->toBe("\x00\x00\x00\x00\x02hi");
});
