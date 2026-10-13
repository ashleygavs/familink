<?php

namespace Database\Factories;

use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResidentChange>
 */
class ResidentChangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'changed_by_user_id' => User::factory(),
            'type' => ResidentChange::HOUSEHOLD_MOVE,
            'summary' => 'Moved to another household.',
            'data' => null,
        ];
    }
}
