<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesFamilyLinks;
use App\Models\Resident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LinkRelativesRequest extends FormRequest
{
    use ValidatesFamilyLinks;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->familyLinkRules($this->resident()->barangay_id);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->familyLinkMessages();
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $resident = $this->resident();

                if (! $this->filled('father_id') && ! $this->filled('mother_id') && ! $this->filled('spouse_id') && (array) $this->input('dependent_ids', []) === []) {
                    $validator->errors()->add('father_id', 'Choose at least one relative to link.');

                    return;
                }

                foreach (['father_id' => 'father', 'mother_id' => 'mother', 'spouse_id' => 'spouse'] as $slot => $label) {
                    if ($this->filled($slot) && $resident->{$slot} !== null) {
                        $validator->errors()->add($slot, "This resident already has a {$label} on record.");
                    }
                }

                $this->guardFamilyLinks($validator, $resident->birthdate, $resident->gender, $resident);
            },
        ];
    }

    private function resident(): Resident
    {
        /** @var Resident $resident */
        $resident = $this->route('resident');

        return $resident;
    }
}
