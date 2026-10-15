<?php

namespace App\NativeComponents;

class Home extends Screen
{
    protected string $title = 'Sample';

    public function go(string $url): void
    {
        $this->navigate($url);
    }
}
