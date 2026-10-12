<?php

namespace Database\Factories;

use App\Models\Barangay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Barangay>
 */
class BarangayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->streetName(),
            'municipality' => fake()->city(),
            'province' => fake()->city(),
        ];
    }
}
