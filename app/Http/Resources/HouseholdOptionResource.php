<?php

namespace App\Http\Resources;

use App\Models\Household;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Household
 */
class HouseholdOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'household_code' => $this->household_code,
            'street_address' => $this->street_address,
            'barangay_id' => $this->barangay_id,
            'head_name' => $this->head?->full_name,
            'members_count' => $this->residents_count ?? $this->residents()->count(),
        ];
    }
}
