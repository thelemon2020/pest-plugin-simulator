<?php

declare(strict_types=1);

use NativePhp\Simulator\DeviceCatalog;
use NativePhp\Simulator\Permissions;

DeviceCatalog::fake('iPhone Latest', 'Pixel Default');

uses()->afterEach(function () {
    Permissions::reset();
})->in(__DIR__);
