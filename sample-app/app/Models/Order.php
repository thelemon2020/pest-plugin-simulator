<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

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
    }
}
