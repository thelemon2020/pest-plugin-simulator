<?php

declare(strict_types=1);

namespace Tests\Support\Codegen;

use Illuminate\Database\Eloquent\Model;

/**
 * A model with no factory: its rows start from the columns' defaults.
 */
final class Gadget extends Model
{
    protected $fillable = ['name', 'status', 'price', 'note'];
}
