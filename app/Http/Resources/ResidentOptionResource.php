<?php

namespace App\Http\Resources;

use App\Enums\ResidentStatus;
use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resident as shown in a picker or a list of suggested relatives. Encoders may link relatives who live in other
 * barangays, so those residents appear too, but only with what is needed to tell people apart: name, gender and birth
 * year. Their exact birthdate, resident ID, street address and household head are shown only to someone who works in
 * their barangay.
 *
 * @mixin Resident
 */
class ResidentOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $full = $this->visibleInFull($request);

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'system_id_no' => $full ? $this->system_id_no : null,
            'birthdate' => $full ? $this->birthdate->toDateString() : null,
            'birth_year' => $this->birthdate->year,
            'birthdate_estimated' => $this->birthdate_estimated,
            'gender' => $this->gender,
            'status' => $this->status->value,
            'is_deceased' => $this->is_deceased,
            'barangay_id' => $this->barangay_id,
            'barangay_name' => $this->barangay?->name,
            'household' => ! $full || $this->household === null ? null : [
                'id' => $this->household->id,
                'household_code' => $this->household->household_code,
                'street_address' => $this->household->street_address,
                'barangay_id' => $this->household->barangay_id,
                'head_name' => $this->household->head?->full_name,
            ],
        ];
    }

    /**
     * Whether the viewer works in this resident's barangay. Records only listed by a relative belong to no barangay
     * yet and are shown in full, so the person can recognise and claim their own record.
     */
    private function visibleInFull(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        if ($this->status === ResidentStatus::Listed) {
            return true;
        }

        $scope = $user->accessibleBarangayIds();

        return $scope === null || ($this->barangay_id !== null && in_array((int) $this->barangay_id, $scope, true));
    }
}
