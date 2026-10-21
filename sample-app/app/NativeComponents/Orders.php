<?php

namespace App\NativeComponents;

use App\Models\Order;

/**
 * The orders in the app's own database, each with a Pay button that writes to it.
 */
class Orders extends Screen
{
    protected string $title = 'Orders';

    /** @var array<int, bool> */
    public array $paid = [];

    public function mount(): void
    {
        $this->load();
    }

    public function pay(int $order): void
    {
        Order::findOrFail($order)->pay();
        $this->load();
    }

    private function load(): void
    {
        $this->paid = Order::query()->orderBy('id')->get()
            ->mapWithKeys(fn (Order $order): array => [$order->id => $order->status === 'paid'])
            ->all();
    }
}
