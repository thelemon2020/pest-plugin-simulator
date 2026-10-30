<?php

declare(strict_types=1);

namespace Tests\Support\Codegen;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Widget>
 */
final class WidgetFactory extends Factory
{
    protected $model = Widget::class;

    private static int $made = 0;

    public function definition(): array
    {
        self::$made++;

        return ['label' => 'Widget '.self::$made, 'size' => self::$made * 10];
    }
}
