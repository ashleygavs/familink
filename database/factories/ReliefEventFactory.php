<?php

namespace Database\Factories;

use App\Enums\AllocationType;
use App\Enums\EventStatus;
use App\Models\Municipality;
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
            'municipality_id' => fn () => Municipality::query()->value('id') ?? Municipality::factory()->create()->id,
            'allocation_type' => AllocationType::PerHousehold,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'status' => EventStatus::Active,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function perResident(?int $limitPerHousehold = null): static
    {
        return $this->state(fn (array $attributes) => [
            'allocation_type' => AllocationType::PerResident,
            'max_claims_per_household' => $limitPerHousehold,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => EventStatus::Draft]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => EventStatus::Closed]);
    }
}
