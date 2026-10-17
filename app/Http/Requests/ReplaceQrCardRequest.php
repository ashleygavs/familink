<?php

namespace App\Http\Requests;

use App\Enums\CardReplacementReason;
use App\Models\Resident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceQrCardRequest extends FormRequest
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
        return ['reason' => ['required', Rule::enum(CardReplacementReason::class)]];
    }
}
