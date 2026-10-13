<?php

namespace App\Actions\Residents\Concerns;

use App\Models\Resident;
use Illuminate\Database\Eloquent\Builder;

trait PrioritisesLocalCandidates
{
    /**
     * Order candidates so the most local come first (the chosen household, then the same barangay).
     * The pool is limited after this ordering, so the best candidates are never cut off, however many
     * residents are on record.
     *
     * @param  Builder<Resident>  $query
     * @param  array<string, mixed>  $context
     * @return Builder<Resident>
     */
    private function prioritise(Builder $query, array $context): Builder
    {
        if ($context['household_id'] !== null) {
            $query->orderByRaw('CASE WHEN household_id = ? THEN 0 ELSE 1 END', [$context['household_id']]);
        }

        if ($context['barangay_id'] !== null) {
            $query->orderByRaw('CASE WHEN barangay_id = ? THEN 0 ELSE 1 END', [$context['barangay_id']]);
        }

        return $query->orderBy('id');
    }
}
