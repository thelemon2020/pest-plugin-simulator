<?php

declare(strict_types=1);

use NativePhp\Simulator\DeviceCatalog;
use NativePhp\Simulator\DeviceState;
use NativePhp\Simulator\ParallelLanes;
use NativePhp\Simulator\Permissions;
use NativePhp\Simulator\Platforms;
use NativePhp\Simulator\Recording;
use NativePhp\Simulator\SuiteRegistration;
use NativePhp\Simulator\SystemUi;
use NativePhp\Simulator\Trace;
use Tests\Support\FakeMachine;

DeviceCatalog::fake('iPhone Latest', 'Pixel Default');
Platforms::use(new FakeMachine);

// PHP 8.3's cycle collector crashes this suite. About half the runs segfault after the
// last test, in the allocator, under the preg_match_all that prints the summary. In 30
// runs each: 14 crashes as is, 15 with PHP's allocator off, 0 with the collector off.
// Valgrind found no bad read or write, and none of the plugin's, Pest's or Symfony
// Process's destructors runs in the collection. The collector frees something that is
// still in use, and the crash only comes when the suite is about this size. 8.4
// doesn't crash, so it keeps its collector. Without it the suite peaks at 80 MB
// instead of 69.
if (PHP_VERSION_ID < 80400) {
    gc_disable();
}

uses()->afterEach(function () {
    Permissions::reset();
    DeviceState::reset();
    SystemUi::beginTest();
    Recording::reset();
    Trace::reset();
    Platforms::use(new FakeMachine);
    SuiteRegistration::forgetRegisteredDevices();
    ParallelLanes::reset();
})->in(__DIR__);
