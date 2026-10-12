<?php

namespace App\NativeComponents;

class Items extends Screen
{
    protected string $title = 'Items';

    public string $opened = '';

    public function open(int $item): void
    {
        $this->opened = "Opened item {$item}";
    }
}
