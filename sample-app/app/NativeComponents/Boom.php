<?php

namespace App\NativeComponents;

use RuntimeException;

/**
 * A button whose PHP throws, so a test can check that the plugin fails on the app's error
 * at once, instead of waiting out the timeout on NativePHP's error screen.
 */
class Boom extends Screen
{
    protected string $title = 'Boom';

    public function explode(): void
    {
        throw new RuntimeException('The sample app blew up on purpose.');
    }
}
