<?php

namespace App\Http\Requests;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Resident;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveHouseholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resident = $this->route('resident');

        // A record outside the user's area answers exactly like one that does not exist.
        abort_unless(
            $resident instanceof Resident && Resident::query()->visibleTo($this->user())->whereKey($resident->id)->exists(),
            404,
        );

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'household_id' => ['nullable', 'integer', 'exists:households,id'],
            'street_address' => ['nullable', 'required_without:household_id', 'string', 'max:255'],
            'household_role' => ['nullable', Rule::enum(HouseholdRole::class)->except(HouseholdRole::Head)],
            'is_head' => ['boolean'],
            'new_head_id' => ['nullable', 'integer', 'exists:residents,id'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $this->boolean('is_head') && ! $this->filled('household_role')) {
                $validator->errors()->add('household_role', 'Choose how this person is related to the household head.');
            }

            $household = $this->filled('household_id') ? Household::query()->find($this->integer('household_id')) : null;

            if ($household !== null && ! $this->user()->canAccessBarangay($household->barangay_id)) {
                $validator->errors()->add('household_id', 'You can only move people to households in the barangays you handle.');
            }
        }];
    }
}
