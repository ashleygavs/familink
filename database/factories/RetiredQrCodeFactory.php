<?php

namespace Database\Factories;

use App\Enums\CardReplacementReason;
use App\Models\Resident;
use App\Models\RetiredQrCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RetiredQrCode>
 */
class RetiredQrCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'qr_code_hash' => hash('sha256', Str::uuid()->toString()),
            'reason' => CardReplacementReason::Lost,
            'replaced_by_user_id' => User::factory(),
            'attempts' => 0,
            'last_attempt_at' => null,
        ];
    }
}
