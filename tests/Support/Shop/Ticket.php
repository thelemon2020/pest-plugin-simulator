<?php

declare(strict_types=1);

namespace Tests\Support\Shop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A model that soft deletes, with a deleted-at column of its own name.
 *
 * @property int $id
 */
final class Ticket extends Model
{
    use SoftDeletes;

    public const DELETED_AT = 'cancelled_at';

    protected $guarded = [];

    public $timestamps = false;
}
