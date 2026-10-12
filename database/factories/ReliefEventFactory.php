<?php

namespace Database\Factories;

use App\Enums\AllocationType;
use App\Models\ReliefEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReliefEvent>
 */
class ReliefEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'allocation_type' => AllocationType::PerHousehold,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'status' => 'Active',
            'created_by_user_id' => User::factory(),
        ];
    }

    public function perResident(): static
    {
        return $this->state(fn (array $attributes) => ['allocation_type' => AllocationType::PerResident]);
    }
}
