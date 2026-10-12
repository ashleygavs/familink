<?php

namespace Database\Factories;

use App\Models\Barangay;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Household>
 */
class HouseholdFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_code' => 'HH-'.fake()->unique()->numerify('######'),
            'barangay_id' => Barangay::factory(),
            'head_resident_id' => null,
            'street_address' => fake()->streetAddress(),
        ];
    }
}
