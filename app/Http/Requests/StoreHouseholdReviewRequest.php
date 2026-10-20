<?php

namespace App\Http\Requests;

use App\Models\Household;
use Illuminate\Foundation\Http\FormRequest;

class StoreHouseholdReviewRequest extends FormRequest
{
    /**
     * An admin may review a pair when they work in the barangay of at least one of the two households.
     */
    public function authorize(): bool
    {
        $barangays = Household::query()
            ->whereIn('id', [(int) $this->input('first_household_id'), (int) $this->input('second_household_id')])
            ->pluck('barangay_id');

        return $barangays->isNotEmpty() && $barangays->contains(fn ($id): bool => $this->user()->canAccessBarangay((int) $id));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'first_household_id' => ['required', 'integer', 'exists:households,id'],
            'second_household_id' => ['required', 'integer', 'exists:households,id', 'different:first_household_id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
