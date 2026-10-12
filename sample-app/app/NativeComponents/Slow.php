<?php

namespace App\NativeComponents;

/**
 * PHP that takes its time on purpose, so a test can tell whether the plugin waits for
 * the app's answer or only for the screen to stop moving.
 */
class Slow extends Screen
{
    protected string $title = 'Slow';

    public string $echo = '';

    public string $result = 'Not started';

    public function updatedEcho(): void
    {
        usleep(300_000);
    }

    public function work(): void
    {
        usleep(2_000_000);
        $this->result = 'Finished';
    }
}
