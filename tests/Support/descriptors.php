<?php

declare(strict_types=1);

// Runs a command while this process holds more descriptors than select() can watch.

use NativePhp\Simulator\Command;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$held = [];

for ($i = 0; $i < 1100; $i++) {
    $held[] = fopen('/dev/null', 'r');
}

echo trim((new Command)->run('echo', ['answered'], timeout: 5));
