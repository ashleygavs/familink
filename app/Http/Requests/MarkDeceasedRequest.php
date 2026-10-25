<?php

namespace App\Http\Requests;

use App\Models\Resident;
use Illuminate\Foundation\Http\FormRequest;

class MarkDeceasedRequest extends FormRequest
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
            'deceased_at' => ['nullable', 'date', 'before_or_equal:today'],
            'new_head_id' => ['nullable', 'integer', 'exists:residents,id'],
        ];
    }
}
