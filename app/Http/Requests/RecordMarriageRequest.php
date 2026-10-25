<?php

namespace App\Http\Requests;

use App\Models\Resident;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class RecordMarriageRequest extends FormRequest
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
            'spouse_id' => ['required', 'integer', 'exists:residents,id'],
            'takes_surname_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && ! Resident::query()->visibleTo($this->user())->whereKey($this->integer('spouse_id'))->exists()) {
                $validator->errors()->add('spouse_id', 'You can only record a marriage with someone in the barangays you handle.');
            }
        }];
    }
}
