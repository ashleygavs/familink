<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnlinkRelativeRequest extends FormRequest
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
        return [
            'kind' => ['required', 'in:father,mother,spouse,child,relative'],
            'id' => ['required_if:kind,child,relative', 'nullable', 'integer'],
        ];
    }
}
