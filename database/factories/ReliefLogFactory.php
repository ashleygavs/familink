<?php

namespace Database\Factories;

use App\Models\ReliefEvent;
use App\Models\ReliefLog;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReliefLog>
 */
class ReliefLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => ReliefEvent::factory(),
            'resident_id' => Resident::factory(),
            'household_id' => fn (array $attributes) => Resident::query()->whereKey($attributes['resident_id'])->value('household_id'),
            'scanned_by_user_id' => User::factory(),
            'status' => 'Claimed',
            'remarks' => null,
        ];
    }
}
