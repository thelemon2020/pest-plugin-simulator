<?php

namespace App\NativeComponents;

use Native\Mobile\Edge\NativeComponent;

/**
 * A screen in the sample app. Each one exercises a few of the plugin's calls, so the
 * plugin's own device tests have something real to tap.
 */
abstract class Screen extends NativeComponent
{
    protected string $title = '';

    public function navTitle(): string
    {
        return $this->title;
    }
}
