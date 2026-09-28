<?php

declare(strict_types=1);

use NativePhp\Simulator\DeviceCatalog;
use NativePhp\Simulator\Permissions;
use NativePhp\Simulator\Platforms;
use Tests\Support\FakeMachine;

DeviceCatalog::fake('iPhone Latest', 'Pixel Default');
Platforms::use(new FakeMachine);

uses()->afterEach(function () {
    Permissions::reset();
    Platforms::use(new FakeMachine);
})->in(__DIR__);
