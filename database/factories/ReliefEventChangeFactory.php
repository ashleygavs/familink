<?php

namespace Database\Factories;

use App\Models\ReliefEvent;
use App\Models\ReliefEventChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReliefEventChange>
 */
class ReliefEventChangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'relief_event_id' => ReliefEvent::factory(),
            'municipality_id' => null,
            'event_title' => fake()->words(3, true),
            'changed_by_user_id' => User::factory(),
            'type' => ReliefEventChange::UPDATED,
            'summary' => 'Updated the event.',
            'data' => null,
        ];
    }
}
