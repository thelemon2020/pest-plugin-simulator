<?php

declare(strict_types=1);

namespace Tests\Support\Codegen;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A model whose factory makes its parent, a Widget, too.
 */
final class Part extends Model
{
    /** @use HasFactory<PartFactory> */
    use HasFactory;

    protected $fillable = ['widget_id', 'name'];

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return PartFactory::new();
    }
}
