<?php

namespace App\NativeComponents;

class Orders extends Screen
{
    protected string $title = 'Orders';

    /** @var array<int, bool> */
    public array $paid = [1 => false, 2 => false, 3 => false];

    public function pay(int $order): void
    {
        $this->paid[$order] = true;
    }
}
