<?php

declare(strict_types=1);

namespace Tests\Support\Codegen;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Part>
 */
final class PartFactory extends Factory
{
    protected $model = Part::class;

    public function definition(): array
    {
        return ['widget_id' => Widget::factory(), 'name' => 'Bolt'];
    }
}
