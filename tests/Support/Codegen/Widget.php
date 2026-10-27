<?php

declare(strict_types=1);

namespace Tests\Support\Codegen;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A model with a factory, soft deletes, and no fillable list.
 */
final class Widget extends Model
{
    /** @use HasFactory<WidgetFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return WidgetFactory::new();
    }
}
