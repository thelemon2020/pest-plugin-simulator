<?php

declare(strict_types=1);

use NativePhp\Simulator\DeviceWipe;
use NativePhp\Simulator\EmulatorBoot;
use Tests\Support\RecordingCommand;

it('cold boots the emulator from an empty userdata image', function () {
    expect(DeviceWipe::emulator(EmulatorBoot::arguments('Pixel 8')))->toBe([
        ...EmulatorBoot::arguments('Pixel 8'),
        '-wipe-data',
        '-no-snapshot-load',
    ]);
});

it('erases a simulator after shutting it down', function () {
    $command = new RecordingCommand;

    DeviceWipe::eraseSimulator($command, 'UDID');

    expect($command->calls)->toBe([
        ['xcrun', ['simctl', 'shutdown', 'UDID']],
        ['xcrun', ['simctl', 'erase', 'UDID']],
    ]);
});

it('deletes a simulator that is already shut down', function () {
    $command = new RecordingCommand;
    $command->failures = ['shutdown' => 'Unable to shutdown device in current state: Shutdown'];

    DeviceWipe::deleteSimulator($command, 'UDID');

    expect($command->calls)->toBe([
        ['xcrun', ['simctl', 'shutdown', 'UDID']],
        ['xcrun', ['simctl', 'delete', 'UDID']],
    ]);
});
