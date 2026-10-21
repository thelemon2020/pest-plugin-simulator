<?php

declare(strict_types=1);

namespace Tests\Support\Shop;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $status
 */
final class Order extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
