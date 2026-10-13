<?php

namespace App\Http\Requests\Concerns;

use App\Actions\Residents\RecordSuggestionFeedback;
use App\Enums\RelativeKind;
use Illuminate\Validation\Rule;

trait ValidatesSuggestionFeedback
{
    /**
     * Rules for the answers given to suggested relatives (the `suggestion_feedback` list).
     *
     * @return array<string, array<int, mixed>>
     */
    protected function suggestionFeedbackRules(): array
    {
        return [
            'suggestion_feedback' => ['nullable', 'array', 'max:200'],
            'suggestion_feedback.*.candidate_id' => ['required', 'integer', Rule::exists('residents', 'id')],
            'suggestion_feedback.*.role' => ['required', Rule::in(RecordSuggestionFeedback::roles())],
            'suggestion_feedback.*.decision' => ['required', Rule::in(RecordSuggestionFeedback::DECISIONS)],
            'suggestion_feedback.*.reclassified_as' => ['nullable', Rule::in(RelativeKind::values())],
            'suggestion_feedback.*.score' => ['nullable', 'integer', 'between:0,300'],
            'suggestion_feedback.*.confidence' => ['nullable', Rule::in(['high', 'medium'])],
            'suggestion_feedback.*.reasons' => ['nullable', 'array', 'max:12'],
            'suggestion_feedback.*.reasons.*' => ['string', 'max:200'],
        ];
    }
}
