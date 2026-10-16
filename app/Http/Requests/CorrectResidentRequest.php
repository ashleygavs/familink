<?php

namespace App\Http\Requests;

use App\Enums\Gender;
use App\Enums\NameSuffix;
use App\Models\Resident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectResidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resident = $this->route('resident');

        return $resident instanceof Resident
            && Resident::query()->visibleTo($this->user())->whereKey($resident->id)->exists();
    }

    protected function prepareForValidation(): void
    {
        $names = [];

        foreach (['first_name', 'middle_name', 'last_name', 'maiden_name'] as $field) {
            $value = $this->input($field);
            $names[$field] = is_string($value) && trim($value) !== '' ? Resident::properCase($value) : null;
        }

        $this->merge($names);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', Rule::enum(NameSuffix::class)],
            'maiden_name' => ['nullable', 'string', 'max:100'],
            'birthdate' => ['required', 'date', 'before_or_equal:today', 'after:1899-12-31'],
            'gender' => ['required', Rule::enum(Gender::class)],
        ];
    }
}
