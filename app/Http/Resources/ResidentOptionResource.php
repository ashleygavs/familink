<?php

namespace App\Http\Resources;

use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Resident
 */
class ResidentOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'system_id_no' => $this->system_id_no,
            'birthdate' => $this->birthdate->toDateString(),
            'birthdate_estimated' => $this->birthdate_estimated,
            'gender' => $this->gender,
            'status' => $this->status->value,
            'is_deceased' => $this->is_deceased,
            'barangay_id' => $this->barangay_id,
            'barangay_name' => $this->barangay?->name,
            'household' => $this->household === null ? null : [
                'id' => $this->household->id,
                'household_code' => $this->household->household_code,
                'street_address' => $this->household->street_address,
                'barangay_id' => $this->household->barangay_id,
                'head_name' => $this->household->head?->full_name,
            ],
        ];
    }
}
