<?php

namespace App\Http\Requests;

use App\Models\Resident;
use Illuminate\Foundation\Http\FormRequest;

class MarkDeceasedRequest extends FormRequest
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
            'deceased_at' => ['nullable', 'date', 'before_or_equal:today'],
            'new_head_id' => ['nullable', 'integer', 'exists:residents,id'],
        ];
    }
}
