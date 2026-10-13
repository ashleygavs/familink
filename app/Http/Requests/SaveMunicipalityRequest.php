<?php

namespace App\Http\Requests;

use App\Enums\RoleName;
use App\Models\Municipality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMunicipalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(RoleName::SuperAdmin);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $municipality = $this->route('municipality');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('municipalities', 'name')
                ->where('province', $this->input('province'))
                ->ignore($municipality instanceof Municipality ? $municipality : null)],
            'province' => ['required', 'string', 'max:255'],
        ];
    }
}
