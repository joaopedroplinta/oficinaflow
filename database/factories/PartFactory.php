<?php

namespace Database\Factories;

use App\Models\Part;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Part> */
class PartFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'name' => fake()->words(3, true),
            'sale_price_cents' => fake()->numberBetween(500, 50000),
            'on_hand' => 10,
            'reserved_quantity' => 0,
            'active' => true,
        ];
    }
}
