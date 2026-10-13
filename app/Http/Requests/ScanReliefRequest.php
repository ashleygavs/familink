<?php

namespace App\Http\Requests;

use App\Models\ReliefEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScanReliefRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $ids = ReliefEvent::query()->visibleTo($this->user())->pluck('id')->all();

        return [
            'event_id' => ['required', 'integer', Rule::in($ids)],
            'code' => ['required', 'string', 'max:255'],
        ];
    }
}
