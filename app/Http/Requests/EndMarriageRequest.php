<?php

namespace App\Http\Requests;

use App\Models\Resident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EndMarriageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resident = $this->route('resident');

        return $resident instanceof Resident
            && Resident::query()->visibleTo($this->user())->whereKey($resident->id)->exists();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'civil_status' => ['required', Rule::in(['Separated', 'Annulled'])],
            'restore_maiden_name_ids' => ['nullable', 'array', 'max:2'],
            'restore_maiden_name_ids.*' => ['integer'],
        ];
    }
}
