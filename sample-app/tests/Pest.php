<?php

use NativePhp\Simulator\Configuration;

pest()->extend(Tests\TestCase::class)->in('Feature', 'Simulator');

// GitHub's macOS runner is several times slower than a Mac. There one read took up to 15
// seconds, and a screen 10 to 20 seconds after the app launched, so a step gets 30.
if (getenv('CI')) {
    Configuration::configure(['timeout' => 30.0]);
}
