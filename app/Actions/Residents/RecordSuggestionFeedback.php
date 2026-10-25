<?php

namespace App\Actions\Residents;

use App\Enums\RelativeKind;
use App\Models\Resident;
use App\Models\SuggestionFeedback;
use App\Models\User;

class RecordSuggestionFeedback
{
    /** The roles a suggestion can be made for, besides the kinds of relative. */
    public const ROLES = ['father', 'mother', 'spouse', 'children'];

    public const DECISIONS = ['accepted', 'rejected', 'unsure', 'reclassified'];

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [...self::ROLES, ...RelativeKind::values()];
    }

    /**
     * Remember the answers a person gave to suggestions made for a resident. Answering the same
     * suggestion again replaces the earlier answer. This never changes a family link.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @param  string  $context  Where the answer was given: "registration" or "approval".
     */
    public function handle(Resident $resident, ?User $user, array $entries, string $context): void
    {
        foreach (array_slice(array_values($entries), 0, 200) as $entry) {
            $candidateId = (int) $entry['candidate_id'];

            if ($candidateId === $resident->id) {
                continue;
            }

            SuggestionFeedback::query()->updateOrCreate(
                ['resident_id' => $resident->id, 'candidate_id' => $candidateId, 'role' => $entry['role']],
                [
                    'decision' => $entry['decision'],
                    'reclassified_as' => $entry['decision'] === 'reclassified' ? ($entry['reclassified_as'] ?? null) : null,
                    'score' => $entry['score'] ?? null,
                    'confidence' => $entry['confidence'] ?? null,
                    'reasons' => array_values((array) ($entry['reasons'] ?? [])),
                    'context' => $context,
                    'decided_by_user_id' => $user?->id,
                ],
            );
        }
    }

    /**
     * The residents already turned down for a resident, by role, so they are not suggested again.
     *
     * @return array<string, list<int>>
     */
    public function rejectedFor(Resident $resident): array
    {
        return $this->rejectedForMany([$resident->id])[$resident->id] ?? [];
    }

    /**
     * What has already been answered for each of several residents, asked in one go.
     *
     * @param  iterable<int>  $residentIds
     * @return array<int, array<string, list<int>>>
     */
    public function rejectedForMany(iterable $residentIds): array
    {
        $rejected = [];

        SuggestionFeedback::query()
            ->whereIn('resident_id', collect($residentIds)->all())
            ->whereIn('decision', ['rejected', 'reclassified'])
            ->get(['resident_id', 'role', 'candidate_id'])
            ->each(function (SuggestionFeedback $feedback) use (&$rejected): void {
                $rejected[$feedback->resident_id][$feedback->role][] = $feedback->candidate_id;
            });

        return $rejected;
    }
}
