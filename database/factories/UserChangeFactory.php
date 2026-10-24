<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserChange>
 */
class UserChangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'barangay_id' => null,
            'target_name' => fake()->name(),
            'target_email' => fake()->safeEmail(),
            'changed_by_user_id' => User::factory(),
            'type' => UserChange::UPDATED,
            'summary' => 'Changed the account.',
            'data' => null,
        ];
    }
}
