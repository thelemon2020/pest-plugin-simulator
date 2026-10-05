<?php

declare(strict_types=1);

use NativePhp\Simulator\DeviceCatalog;
use NativePhp\Simulator\ParallelLanes;
use NativePhp\Simulator\Permissions;
use NativePhp\Simulator\Platforms;
use NativePhp\Simulator\Recording;
use NativePhp\Simulator\SuiteRegistration;
use Tests\Support\FakeMachine;

DeviceCatalog::fake('iPhone Latest', 'Pixel Default');
Platforms::use(new FakeMachine);

uses()->afterEach(function () {
    Permissions::reset();
    Recording::reset();
    Platforms::use(new FakeMachine);
    SuiteRegistration::forgetRegisteredDevices();
    ParallelLanes::reset();
})->in(__DIR__);
