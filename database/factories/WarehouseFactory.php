<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Warehouse>
 */
class WarehouseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            "name" => "Warehouse " . fake()->unique()->words(3, true),
            "location" => fake()->unique()->address(),
            "capacity" => fake()->numberBetween(100, 500)
        ];
    }
}
