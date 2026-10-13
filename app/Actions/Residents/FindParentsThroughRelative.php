<?php

namespace App\Actions\Residents;

use App\Enums\ResidentStatus;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Suggests a person's parents when they only know how they are related to someone already
 * registered, for example "Marcus is my brother" or "Mark is my uncle".
 *
 * @phpstan-type Match array{resident: Resident, score: int, confidence: string, reasons: list<string>}
 */
class FindParentsThroughRelative
{
    /**
     * Combine what several relatives say about the same person: a candidate named by more than one
     * relative (for example both a brother and a cousin) is more likely to be right.
     *
     * @param  list<array{relative: Resident, relation: string}>  $relatives
     * @return array{father: list<Match>, mother: list<Match>}
     */
    public function handleMany(array $relatives): array
    {
        $merged = ['father' => [], 'mother' => []];

        foreach ($relatives as $entry) {
            foreach ($this->handle($entry['relative'], $entry['relation']) as $slot => $matches) {
                foreach ($matches as $match) {
                    $id = $match['resident']->id;
                    $existing = $merged[$slot][$id] ?? null;

                    if ($existing === null) {
                        $merged[$slot][$id] = $match;

                        continue;
                    }

                    $score = min(100, max($existing['score'], $match['score']) + 15);
                    $merged[$slot][$id] = [
                        'resident' => $match['resident'],
                        'score' => $score,
                        'confidence' => $score >= 70 ? 'high' : 'medium',
                        'reasons' => array_values(array_unique([...$existing['reasons'], ...$match['reasons']])),
                    ];
                }
            }
        }

        foreach (['father', 'mother'] as $slot) {
            $matches = array_values($merged[$slot]);
            usort($matches, fn (array $a, array $b) => $b['score'] <=> $a['score']);
            $merged[$slot] = $matches;
        }

        return $merged;
    }

    /**
     * @return array{father: list<Match>, mother: list<Match>}
     */
    public function handle(Resident $relative, string $relation): array
    {
        $result = ['father' => [], 'mother' => []];
        $score = $relation === 'sibling' ? 90 : 60;
        $seen = [];

        foreach ($this->candidates($relative, $relation) as $candidate) {
            if (isset($seen[$candidate['resident']->id])) {
                continue;
            }

            $seen[$candidate['resident']->id] = true;
            $slot = $candidate['resident']->gender === 'Male' ? 'father' : 'mother';

            $result[$slot][] = [
                'resident' => $candidate['resident'],
                'score' => $score,
                'confidence' => $score >= 70 ? 'high' : 'medium',
                'reasons' => [$candidate['reason']],
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{resident: Resident, reason: string}>
     */
    private function candidates(Resident $relative, string $relation): array
    {
        $name = $relative->first_name;

        return match ($relation) {
            'sibling' => $this->parentsOf($relative, "{role} of {$name}, your brother or sister"),
            'grandparent' => $this->childrenOf($relative, "Child of {$name}, your grandparent"),
            'uncle_aunt' => $this->siblingsOf($relative, "Brother or sister of {$name}, your uncle or aunt"),
            'cousin' => $this->cousinParents($relative),
            'nephew_niece' => $this->nephewGrandparents($relative),
            default => [],
        };
    }

    /**
     * @return array<int, array{resident: Resident, reason: string}>
     */
    private function parentsOf(Resident $person, string $reasonTemplate): array
    {
        $parents = [];

        foreach ([['father', $person->father], ['mother', $person->mother]] as [$role, $parent]) {
            if ($parent !== null && $parent->status !== ResidentStatus::Archived) {
                $parents[] = ['resident' => $parent, 'reason' => str_replace('{role}', ucfirst($role), $reasonTemplate)];
            }
        }

        return $parents;
    }

    /**
     * @return array<int, array{resident: Resident, reason: string}>
     */
    private function childrenOf(Resident $person, string $reason): array
    {
        return $this->mapReason($person->childrenAsFather->merge($person->childrenAsMother), $reason);
    }

    /**
     * @return array<int, array{resident: Resident, reason: string}>
     */
    private function siblingsOf(Resident $person, string $reason): array
    {
        if ($person->father_id === null && $person->mother_id === null) {
            return [];
        }

        $siblings = Resident::query()
            ->whereNot('status', ResidentStatus::Archived->value)
            ->whereKeyNot($person->id)
            ->where(fn (Builder $query) => $query
                ->when($person->father_id, fn (Builder $inner, int $id) => $inner->orWhere('father_id', $id))
                ->when($person->mother_id, fn (Builder $inner, int $id) => $inner->orWhere('mother_id', $id)))
            ->get();

        return $this->mapReason($siblings, $reason);
    }

    /**
     * The parents of a nephew's or niece's own parents are the person's parents (their grandparents).
     *
     * @return array<int, array{resident: Resident, reason: string}>
     */
    private function nephewGrandparents(Resident $nephew): array
    {
        $candidates = [];

        foreach ([$nephew->father, $nephew->mother] as $parent) {
            if ($parent !== null) {
                array_push($candidates, ...$this->parentsOf($parent, "{role} of {$parent->first_name}, parent of your nephew or niece {$nephew->first_name}"));
            }
        }

        return $candidates;
    }

    /**
     * The brothers and sisters of the cousin's parents are the person's own parents.
     *
     * @return array<int, array{resident: Resident, reason: string}>
     */
    private function cousinParents(Resident $cousin): array
    {
        $candidates = [];

        foreach ([$cousin->father, $cousin->mother] as $parent) {
            if ($parent !== null) {
                array_push($candidates, ...$this->siblingsOf($parent, "Brother or sister of {$parent->first_name}, parent of your cousin {$cousin->first_name}"));
            }
        }

        return $candidates;
    }

    /**
     * @param  Collection<int, Resident>  $residents
     * @return array<int, array{resident: Resident, reason: string}>
     */
    private function mapReason(Collection $residents, string $reason): array
    {
        return $residents
            ->filter(fn (Resident $resident) => $resident->status !== ResidentStatus::Archived)
            ->map(fn (Resident $resident): array => ['resident' => $resident, 'reason' => $reason])
            ->values()
            ->all();
    }
}
