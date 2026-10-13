<?php

namespace App\Actions\Residents;

use App\Enums\RelativeKind;
use App\Enums\ResidentStatus;
use App\Models\Resident;
use Illuminate\Support\Collection;

/**
 * Suggests more relatives from the relatives a person has already confirmed. If Marcus is your cousin, his
 * parents are very likely your uncle and aunt, and his brothers and sisters are likely your cousins too.
 * Every result is only a suggestion the person confirms.
 *
 * @phpstan-type Relative array{resident: Resident, relation: string, score: int, confidence: string, reasons: list<string>}
 */
class FindRelativesThroughRelatives
{
    private const BASE_SCORE = 60;

    /**
     * @param  list<array{relative: Resident, relation: string}>  $chosen  Relatives the person already confirmed.
     * @return list<Relative>
     */
    public function handle(array $chosen): array
    {
        $chosenIds = array_map(fn (array $entry): int => $entry['relative']->id, $chosen);
        $merged = [];

        foreach ($chosen as $entry) {
            $relative = $entry['relative']->loadMissing(['father', 'mother', 'spouse', 'childrenAsFather', 'childrenAsMother']);

            foreach ($this->derive($relative, $entry['relation']) as [$resident, $kind, $reason]) {
                if (in_array($resident->id, $chosenIds, true) || $resident->status === ResidentStatus::Archived) {
                    continue;
                }

                $key = "{$resident->id}:{$kind->value}";
                $existing = $merged[$key] ?? null;
                $score = $existing === null ? self::BASE_SCORE : min(100, $existing['score'] + 15);

                $merged[$key] = [
                    'resident' => $resident,
                    'relation' => $kind->value,
                    'score' => $score,
                    'confidence' => $score >= 70 ? 'high' : 'medium',
                    'reasons' => array_values(array_unique([...($existing['reasons'] ?? []), $reason])),
                ];
            }
        }

        $relatives = array_values($merged);
        usort($relatives, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $relatives;
    }

    /**
     * @return list<array{0: Resident, 1: RelativeKind, 2: string}>
     */
    private function derive(Resident $relative, string $relation): array
    {
        $name = $relative->first_name;

        return match (RelativeKind::tryFrom($relation)) {
            RelativeKind::Cousin => [
                ...$this->as($this->parents($relative), RelativeKind::UncleAunt, "Parent of your cousin {$name}"),
                ...$this->as($this->siblings($relative), RelativeKind::Cousin, "Brother or sister of your cousin {$name}"),
            ],
            RelativeKind::UncleAunt => [
                ...$this->as($this->children($relative), RelativeKind::Cousin, "Child of your uncle or aunt {$name}"),
                ...$this->as($this->spouse($relative), RelativeKind::UncleAunt, "Spouse of your uncle or aunt {$name}"),
                ...$this->as($this->parents($relative), RelativeKind::Grandparent, "Parent of your uncle or aunt {$name}"),
            ],
            RelativeKind::Sibling => [
                ...$this->as($this->children($relative), RelativeKind::NephewNiece, "Child of your brother or sister {$name}"),
                ...$this->as($this->siblings($relative), RelativeKind::Sibling, "Brother or sister of your brother or sister {$name}"),
            ],
            RelativeKind::Grandparent => [
                ...$this->as($this->spouse($relative), RelativeKind::Grandparent, "Spouse of your grandparent {$name}"),
                ...$this->as($this->children($relative), RelativeKind::UncleAunt, "Child of your grandparent {$name}"),
            ],
            RelativeKind::NephewNiece => [
                ...$this->as($this->parents($relative), RelativeKind::Sibling, "Parent of your nephew or niece {$name}"),
                ...$this->as($this->siblings($relative), RelativeKind::NephewNiece, "Brother or sister of your nephew or niece {$name}"),
            ],
            RelativeKind::Grandchild => $this->as($this->siblings($relative), RelativeKind::Grandchild, "Brother or sister of your grandchild {$name}"),
            default => [],
        };
    }

    /**
     * @param  iterable<Resident>  $residents
     * @return list<array{0: Resident, 1: RelativeKind, 2: string}>
     */
    private function as(iterable $residents, RelativeKind $kind, string $reason): array
    {
        $result = [];

        foreach ($residents as $resident) {
            $result[] = [$resident, $kind, $reason];
        }

        return $result;
    }

    /**
     * @return list<Resident>
     */
    private function parents(Resident $resident): array
    {
        return array_values(array_filter([$resident->father, $resident->mother]));
    }

    /**
     * @return list<Resident>
     */
    private function spouse(Resident $resident): array
    {
        return $resident->spouse === null ? [] : [$resident->spouse];
    }

    /**
     * @return Collection<int, Resident>
     */
    private function children(Resident $resident): Collection
    {
        return $resident->childrenAsFather->merge($resident->childrenAsMother)->unique('id')->values();
    }

    /**
     * Other children of the same father or mother.
     *
     * @return Collection<int, Resident>
     */
    private function siblings(Resident $resident): Collection
    {
        if ($resident->father_id === null && $resident->mother_id === null) {
            return new Collection;
        }

        return Resident::query()
            ->whereKeyNot($resident->id)
            ->where(fn ($query) => $query
                ->when($resident->father_id, fn ($inner, int $id) => $inner->orWhere('father_id', $id))
                ->when($resident->mother_id, fn ($inner, int $id) => $inner->orWhere('mother_id', $id)))
            ->get();
    }
}
