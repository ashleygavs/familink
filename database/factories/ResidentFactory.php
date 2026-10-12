<?php

namespace Database\Factories;

use App\Enums\ResidentStatus;
use App\Models\Household;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Resident>
 */
class ResidentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'system_id_no' => 'FL-'.fake()->unique()->numerify('########'),
            'qr_code_hash' => Str::random(40),
            'household_id' => Household::factory(),
            'barangay_id' => fn (array $attributes) => Household::query()->whereKey($attributes['household_id'])->value('barangay_id'),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->lastName(),
            'last_name' => fake()->lastName(),
            'birthdate' => fake()->dateTimeBetween('-80 years', '-1 year'),
            'gender' => fake()->randomElement(['Male', 'Female']),
            'civil_status' => 'Single',
            'household_role' => null,
            'spouse_id' => null,
            'father_id' => null,
            'mother_id' => null,
            'status' => ResidentStatus::Approved,
            'approved_by_user_id' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ResidentStatus::Pending]);
    }
}
