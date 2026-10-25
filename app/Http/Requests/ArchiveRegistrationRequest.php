<?php

namespace App\Http\Requests;

use App\Models\Resident;
use Illuminate\Foundation\Http\FormRequest;

class ArchiveRegistrationRequest extends FormRequest
{
    /**
     * Admins only archive residents of the barangays they handle.
     */
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

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:300']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Please write why this registration is being archived.',
            'reason.min' => 'Please write a little more: at least 5 characters.',
            'reason.max' => 'Please keep the reason to 300 characters or fewer.',
        ];
    }
}
