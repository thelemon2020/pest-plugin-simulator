<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use NativePhp\Simulator\Configuration;

// Each test's rows go in a transaction that is rolled back after it. The plugin copies the
// database through the same connection, so the app gets the rows the test made.
pest()->extend(Tests\TestCase::class)->use(DatabaseTransactions::class)->in('Feature', 'Simulator');

// GitHub's macOS runner is several times slower than a Mac. There one read took up to 15
// seconds, a screen came 10 to 20 seconds after the app launched, and the whole runner
// stalled for half a minute at times, so a step gets 60.
if (getenv('CI')) {
    Configuration::configure(['timeout' => 60.0]);
}
