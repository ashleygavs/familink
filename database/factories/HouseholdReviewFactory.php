<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\HouseholdReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HouseholdReview>
 */
class HouseholdReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_household_id' => Household::factory(),
            'second_household_id' => Household::factory(),
            'reason' => 'Visited both homes. The couple work in different places.',
            'reviewed_by_user_id' => User::factory(),
        ];
    }
}
