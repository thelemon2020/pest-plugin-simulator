<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ShipOrder implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(public Order $order) {}

    public function handle(): void {}
}
