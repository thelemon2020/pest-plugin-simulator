<?php

declare(strict_types=1);

namespace Tests\Support\Shop;

use Illuminate\Queue\SerializesModels;

final class OrderPaid
{
    use SerializesModels;

    public function __construct(public Order $order) {}
}
