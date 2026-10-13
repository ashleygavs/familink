<?php

namespace App\Actions\Residents;

use App\Enums\ResidentStatus;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Works out a person's blood relatives from their parents: siblings, grandparents,
 * uncles and aunts, and cousins. Nothing here is typed in; it is read from the family links on record.
 *
 * @phpstan-type Relative array{resident: Resident, relation: string}
 */
class FindBloodRelatives
{
    private const LIMIT = 15;

    /**
     * @param  int|null  $selfId  A record to leave out of the results (the person themselves).
     * @return array{siblings: array<int, Relative>, grandparents: array<int, Relative>, uncles_aunts: array<int, Relative>, cousins: array<int, Relative>}
     */
    public function handle(?int $fatherId, ?int $motherId, ?int $selfId = null): array
    {
        $result = ['siblings' => [], 'grandparents' => [], 'uncles_aunts' => [], 'cousins' => []];

        $parents = Resident::query()
            ->whereIn('id', array_values(array_filter([$fatherId, $motherId])))
            ->get();

        if ($parents->isEmpty()) {
            return $result;
        }

        $parentIds = array_values(array_map('intval', $parents->modelKeys()));
        $grandparentSides = $this->grandparentSides($parents, $fatherId);

        $result['siblings'] = $this->siblings($parentIds, $fatherId, $motherId, $selfId);
        $result['grandparents'] = $this->grandparents($grandparentSides);

        $unclesAunts = $this->unclesAunts($grandparentSides, $parentIds);
        $result['uncles_aunts'] = $unclesAunts['relatives'];
        $result['cousins'] = $this->cousins($unclesAunts['ids']);

        return $result;
    }

    /**
     * @param  list<int>  $parentIds
     * @return array<int, Relative>
     */
    private function siblings(array $parentIds, ?int $fatherId, ?int $motherId, ?int $selfId): array
    {
        $children = $this->base()
            ->where(fn (Builder $query) => $query->whereIn('father_id', $parentIds)->orWhereIn('mother_id', $parentIds))
            ->when($selfId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->orderBy('birthdate')
            ->limit(self::LIMIT)
            ->get();

        $bothParentsKnown = $fatherId !== null && $motherId !== null;

        return $children->values()->map(function (Resident $child) use ($bothParentsKnown, $fatherId, $motherId): array {
            $label = $child->gender === 'Male' ? 'Brother' : 'Sister';
            $half = $bothParentsKnown && ! ($child->father_id === $fatherId && $child->mother_id === $motherId);

            return ['resident' => $child, 'relation' => $half ? "Half-{$this->lower($label)}" : $label];
        })->all();
    }

    /**
     * The grandparent ids, each with the side of the family they are on.
     *
     * @param  Collection<int, Resident>  $parents
     * @return array<int, string>
     */
    private function grandparentSides($parents, ?int $fatherId): array
    {
        $sides = [];

        foreach ($parents as $parent) {
            $side = $parent->id === $fatherId ? "father's side" : "mother's side";

            foreach ([$parent->father_id, $parent->mother_id] as $grandparentId) {
                if ($grandparentId !== null) {
                    $sides[$grandparentId] = $side;
                }
            }
        }

        return $sides;
    }

    /**
     * @param  array<int, string>  $sides
     * @return array<int, Relative>
     */
    private function grandparents(array $sides): array
    {
        if ($sides === []) {
            return [];
        }

        return $this->base()
            ->whereIn('id', array_keys($sides))
            ->limit(self::LIMIT)
            ->get()
            ->values()
            ->map(fn (Resident $grandparent): array => [
                'resident' => $grandparent,
                'relation' => ($grandparent->gender === 'Male' ? 'Grandfather' : 'Grandmother')." ({$sides[$grandparent->id]})",
            ])
            ->all();
    }

    /**
     * @param  array<int, string>  $sides
     * @param  list<int>  $parentIds
     * @return array{relatives: array<int, Relative>, ids: list<int>}
     */
    private function unclesAunts(array $sides, array $parentIds): array
    {
        if ($sides === []) {
            return ['relatives' => [], 'ids' => []];
        }

        $grandparentIds = array_keys($sides);

        $siblingsOfParents = $this->base()
            ->where(fn (Builder $query) => $query->whereIn('father_id', $grandparentIds)->orWhereIn('mother_id', $grandparentIds))
            ->whereKeyNot($parentIds)
            ->limit(self::LIMIT)
            ->get();

        $relatives = $siblingsOfParents->values()->map(function (Resident $relative) use ($sides): array {
            $side = $sides[$relative->father_id] ?? $sides[$relative->mother_id] ?? null;

            return [
                'resident' => $relative,
                'relation' => ($relative->gender === 'Male' ? 'Uncle' : 'Aunt').($side ? " ({$side})" : ''),
            ];
        })->all();

        return ['relatives' => $relatives, 'ids' => array_values(array_map('intval', $siblingsOfParents->modelKeys()))];
    }

    /**
     * @param  list<int>  $uncleAuntIds
     * @return array<int, Relative>
     */
    private function cousins(array $uncleAuntIds): array
    {
        if ($uncleAuntIds === []) {
            return [];
        }

        return $this->base()
            ->where(fn (Builder $query) => $query->whereIn('father_id', $uncleAuntIds)->orWhereIn('mother_id', $uncleAuntIds))
            ->limit(self::LIMIT)
            ->get()
            ->values()
            ->map(fn (Resident $cousin): array => ['resident' => $cousin, 'relation' => 'Cousin'])
            ->all();
    }

    /**
     * @return Builder<Resident>
     */
    private function base(): Builder
    {
        return Resident::query()
            ->with(['barangay', 'household'])
            ->whereNot('status', ResidentStatus::Archived->value);
    }

    private function lower(string $value): string
    {
        return strtolower($value);
    }
}
