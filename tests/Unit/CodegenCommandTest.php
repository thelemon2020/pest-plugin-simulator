<?php

declare(strict_types=1);

use Tests\Support\LaravelTestCase;

uses(LaravelTestCase::class);

it('refuses a port that is not one', function (string $port) {
    $this->artisan('nativephp:simulator', ['action' => 'codegen', '--ios' => true, '--port' => $port])
        ->expectsOutputToContain('--port takes a port from 1 to 65535')
        ->assertExitCode(1);
})->with(['abc', '0', '70000']);
