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

        // Households outside the user's area, or that do not exist, answer the same: not found.
        abort_unless($barangays->contains(fn ($id): bool => $this->user()->canAccessBarangay((int) $id)), 404);

        return true;
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
