<?php

use NativePhp\Simulator\Configuration;

pest()->extend(Tests\TestCase::class)->in('Feature', 'Simulator');

// GitHub's macOS runner is several times slower than a Mac. There one read took up to 15
// seconds, a screen came 10 to 20 seconds after the app launched, and the whole runner
// stalled for half a minute at times, so a step gets 60.
if (getenv('CI')) {
    Configuration::configure(['timeout' => 60.0]);
}
