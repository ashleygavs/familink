<?php

namespace App\Http\Requests;

use App\Enums\RoleName;
use App\Models\Barangay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBarangayRequest extends FormRequest
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
        $barangay = $this->route('barangay');
        $ignored = $barangay instanceof Barangay ? $barangay : null;

        return [
            'municipality_id' => ['required', 'integer', 'exists:municipalities,id'],
            'name' => ['required', 'string', 'max:255', Rule::unique('barangays', 'name')->where('municipality_id', $this->input('municipality_id'))->ignore($ignored)],
            'code' => ['required', 'string', 'max:10', 'alpha_num', Rule::unique('barangays', 'code')->ignore($ignored)],
        ];
    }
}
