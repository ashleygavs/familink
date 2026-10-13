<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesSuggestionFeedback;
use Illuminate\Foundation\Http\FormRequest;

class SuggestionFeedbackRequest extends FormRequest
{
    use ValidatesSuggestionFeedback;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = $this->suggestionFeedbackRules();
        $rules['suggestion_feedback'] = ['required', 'array', 'min:1', 'max:200'];

        return $rules;
    }
}
