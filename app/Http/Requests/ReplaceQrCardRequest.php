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
        return ['reason' => ['required', Rule::enum(CardReplacementReason::class)]];
    }
}
