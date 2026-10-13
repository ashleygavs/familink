<?php

namespace App\Actions\Residents;

use App\Actions\Residents\Concerns\PrioritisesLocalCandidates;
use App\Enums\RelativeKind;
use App\Enums\ResidentStatus;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Suggests registered residents who may be a brother or sister, grandparent or grandchild, uncle or aunt,
 * nephew or niece, or cousin of the person being registered.
 *
 * Family names are compared on both sides of the family: the surname (father's side) and the middle
 * name (the mother's maiden surname), including the names of the candidate's parents. A person is
 * only ever a suggestion; the registrant confirms it.
 *
 * @phpstan-type Relative array{resident: Resident, relation: string, score: int, confidence: string, reasons: list<string>}
 */
class FindLikelyRelatives
{
    use PrioritisesLocalCandidates;

    private const HIGH = 70;

    private const MEDIUM = 45;

    private const POOL = 300;

    /**
     * @param  array<string, mixed>  $context  The matching context built by FindFamilyMatches.
     * @param  list<int>  $parentIds  Residents already suggested as the person's father or mother.
     * @return list<Relative>
     */
    public function handle(array $context, array $parentIds): array
    {
        $keys = ['paternal' => $context['parent_surname'], 'middle' => $context['middle']];

        if ($keys['paternal'] === null && $keys['middle'] === null && $context['household_id'] === null) {
            return [];
        }

        $matches = [];

        foreach ($this->pool($context, $parentIds, $keys) as $candidate) {
            $gap = (int) $candidate->birthdate->diffInYears($context['birth'], false);
            $signals = $this->signals($candidate, $keys, $context, $parentIds);

            $isParentSuggestion = in_array($candidate->id, $parentIds, true);

            foreach ($this->relationsFor($signals, $gap) as [$relation, $penalty]) {
                if (in_array($candidate->id, $context['rejected']['relatives'][$relation->value] ?? [], true)) {
                    continue;
                }

                // Someone suggested as a parent could just as well be an uncle or aunt, so show them there too.
                if ($isParentSuggestion && $relation !== RelativeKind::UncleAunt) {
                    continue;
                }

                [$score, $reasons] = $this->score($candidate, $relation, $gap, $signals);
                $score -= $penalty;

                if ($isParentSuggestion) {
                    $reasons[] = 'Also suggested as a parent above';
                }

                if ($score >= self::MEDIUM) {
                    $matches[] = [
                        'resident' => $candidate,
                        'relation' => $relation->value,
                        'score' => $score,
                        'confidence' => $score >= self::HIGH ? 'high' : 'medium',
                        'reasons' => $reasons,
                    ];
                }
            }
        }

        usort($matches, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        $perKind = [];
        $limit = $context['limit'] ?? 3;

        return array_values(array_filter($matches, function (array $match) use (&$perKind, $limit): bool {
            $perKind[$match['relation']] = ($perKind[$match['relation']] ?? 0) + 1;

            return $perKind[$match['relation']] <= $limit;
        }));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<int>  $parentIds
     * @param  array{paternal: string|null, middle: string|null}  $keys
     * @return Collection<int, Resident>
     */
    private function pool(array $context, array $parentIds, array $keys): Collection
    {
        $names = array_values(array_unique(array_filter([$keys['paternal'], $keys['middle']])));

        $query = Resident::query()
            ->with(['barangay', 'household', 'father', 'mother'])
            ->whereNot('status', ResidentStatus::Archived->value)
            ->when($context['subject'], fn (Builder $query, Resident $subject) => $query->whereKeyNot($subject->id))
            ->when($context['scope'], fn (Builder $query, int $barangayId) => $query->where(
                fn (Builder $scoped) => $scoped
                    ->where('barangay_id', $barangayId)
                    ->orWhereHas('listedBy', fn (Builder $lister) => $lister->where('barangay_id', $barangayId)),
            ))
            ->where(function (Builder $query) use ($context, $names): void {
                $query->whereRaw('1 = 0');

                if ($names !== []) {
                    $query->orWhereIn('last_name_key', $names)
                        ->orWhereIn('middle_name_key', $names)
                        ->orWhereHas('mother', fn (Builder $mother) => $mother->whereIn('last_name_key', $names)->orWhereIn('maiden_name_key', $names))
                        ->orWhereHas('father', fn (Builder $father) => $father->whereIn('last_name_key', $names));
                }

                if ($context['household_id'] !== null) {
                    $query->orWhere('household_id', $context['household_id']);
                }
            });

        return $this->prioritise($query, $context)->limit(self::POOL)->get();
    }

    /**
     * What links the candidate to the person: a shared surname, a shared mother's family name, or a
     * surname that matches the other side's family name.
     *
     * @param  array{paternal: string|null, middle: string|null}  $keys
     * @param  array<string, mixed>  $context
     * @param  list<int>  $parentIds
     * @return array{paternal: bool, maternal: bool, cross: bool, household: bool, shares_parent: bool, barangay: bool, surname_match: bool}
     */
    private function signals(Resident $candidate, array $keys, array $context, array $parentIds): array
    {
        $fatherSurname = $candidate->father?->last_name_key;
        $motherFamilies = array_filter([$candidate->mother?->last_name_key, $candidate->mother?->maiden_name_key]);
        $surnameMatch = $keys['paternal'] !== null && $candidate->last_name_key === $keys['paternal'];

        return [
            'paternal' => $keys['paternal'] !== null && ($surnameMatch || $fatherSurname === $keys['paternal']),
            'maternal' => $keys['middle'] !== null
                && ($candidate->middle_name_key === $keys['middle'] || in_array($keys['middle'], $motherFamilies, true)),
            'cross' => ($keys['middle'] !== null && ($candidate->last_name_key === $keys['middle'] || $fatherSurname === $keys['middle']))
                || ($keys['paternal'] !== null && ($candidate->middle_name_key === $keys['paternal'] || in_array($keys['paternal'], $motherFamilies, true))),
            'household' => $context['household_id'] !== null && $candidate->household_id === $context['household_id'],
            'shares_parent' => in_array($candidate->father_id, $parentIds, true) || in_array($candidate->mother_id, $parentIds, true),
            'barangay' => $context['barangay_id'] !== null && $candidate->barangay_id === $context['barangay_id'],
            'surname_match' => $surnameMatch,
        ];
    }

    /**
     * The ways the candidate may be related, best fit first. A person who could be an uncle or a cousin is
     * suggested as both, the second one a little less strongly.
     *
     * @param  array{paternal: bool, maternal: bool, cross: bool, household: bool, shares_parent: bool, barangay: bool, surname_match: bool}  $signals
     * @return list<array{0: RelativeKind, 1: int}>
     */
    private function relationsFor(array $signals, int $gap): array
    {
        $years = abs($gap);
        $kin = $signals['paternal'] || $signals['maternal'] || $signals['cross'];

        $siblingFit = $gap >= -12 && $gap <= 20 && (
            $signals['shares_parent']
            || $signals['household']
            || ($signals['surname_match'] && $signals['barangay'] && $years <= 12)
            || ($signals['paternal'] && $signals['maternal'])
        );

        if ($siblingFit) {
            $relations = [[RelativeKind::Sibling, 0]];

            // Someone in another household who looks like a sibling may just as well be a cousin.
            if ($kin && ! $signals['household']) {
                $relations[] = [RelativeKind::Cousin, 10];
            }

            return $relations;
        }

        if (! $kin) {
            return [];
        }

        $relations = [];

        if ($gap >= 48 && $gap <= 95) {
            $relations[] = [RelativeKind::Grandparent, 0];
        } elseif ($gap <= -48 && $gap >= -95) {
            $relations[] = [RelativeKind::Grandchild, 0];
        } elseif ($gap >= 15 && $gap <= 47) {
            $relations[] = [RelativeKind::UncleAunt, 0];

            if ($gap <= 30 && ! $signals['household']) {
                $relations[] = [RelativeKind::Cousin, 10];
            }
        } elseif ($gap <= -15 && $gap >= -47) {
            $relations[] = [RelativeKind::NephewNiece, 0];

            if ($gap >= -30 && ! $signals['household']) {
                $relations[] = [RelativeKind::Cousin, 10];
            }
        } elseif ($years < 15 && ! $signals['household']) {
            $relations[] = [RelativeKind::Cousin, 0];
        }

        return $relations;
    }

    /**
     * @param  array{paternal: bool, maternal: bool, cross: bool, household: bool, shares_parent: bool, barangay: bool, surname_match: bool}  $signals
     * @return array{0: int, 1: list<string>}
     */
    private function score(Resident $candidate, RelativeKind $relation, int $gap, array $signals): array
    {
        $years = abs($gap);
        $score = 0;
        $reasons = [];

        $add = function (int $points, string $reason, bool $applies) use (&$score, &$reasons): void {
            if ($applies) {
                $score += $points;
                $reasons[] = $reason;
            }
        };

        $add(40, $signals['surname_match'] ? 'Same surname' : 'Their father has your surname', $signals['paternal']);
        $add(30, "Shares your mother's family name", $signals['maternal']);
        $add(25, "Linked through your parents' family names", $signals['cross']);
        $add(30, 'Lives in the household you chose', $signals['household']);
        $add(30, 'Has the same parent as someone suggested above', $signals['shares_parent']);
        $add(
            $relation === RelativeKind::Sibling ? ($years <= 10 ? 20 : 10) : 15,
            $relation === RelativeKind::Sibling ? 'Similar age' : "About {$years} years ".($gap > 0 ? 'older' : 'younger'),
            true,
        );
        $add(10, 'Same barangay', $signals['barangay']);

        return [$score, $reasons];
    }
}
