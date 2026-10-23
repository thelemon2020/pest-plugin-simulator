<?php

namespace App\Models;

use App\Events\OrderPaid;
use App\Jobs\ShipOrder;
use App\Mail\Receipt;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * An order the app keeps in its own database. A test creates them on the host, and the
 * plugin copies the database into the app before the first screen.
 */
#[Fillable(['status', 'total', 'paid_at'])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
        ];
    }

    public function pay(): void
    {
        $this->update(['status' => 'paid', 'paid_at' => now()]);

        OrderPaid::dispatch($this);
        Mail::to('customer@example.test')->send(new Receipt($this));
        Storage::disk('local')->put("receipts/{$this->id}.txt", "Order {$this->id}: {$this->total}");
    }

    public function ship(): void
    {
        Bus::batch([new ShipOrder($this)])->name("ship-{$this->id}")->dispatch();
    }
}
