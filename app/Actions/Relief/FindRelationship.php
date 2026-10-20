<?php

namespace App\Actions\Relief;

use App\Models\Resident;

/**
 * Works out how two residents are related, from their recorded parents and marriages, however far apart they live.
 * It follows parents upwards from both people (and from their spouses, for relatives by marriage), then describes the
 * link the same way the family tree does.
 *
 * @phpstan-type Found array{second_is: string|null, first_is: string|null}
 */
class FindRelationship
{
    /** How many generations up it looks for a shared ancestor. */
    private const MAX_GENERATIONS = 10;

    /**
     * What each person is to the other. Both are null when the records show no link.
     *
     * @return Found
     */
    public function handle(Resident $first, Resident $second): array
    {
        $people = [$first->id => $first, $second->id => $second];

        $spouseIds = array_values(array_filter([$first->spouse_id, $second->spouse_id], fn (?int $id): bool => $id !== null && ! isset($people[$id])));
        $this->load($people, $spouseIds);

        $frontier = array_keys($people);

        for ($generation = 0; $generation < self::MAX_GENERATIONS && $frontier !== []; $generation++) {
            $parentIds = [];

            foreach ($frontier as $id) {
                foreach ([$people[$id]->father_id, $people[$id]->mother_id] as $parentId) {
                    if ($parentId !== null && ! isset($people[$parentId])) {
                        $parentIds[$parentId] = $parentId;
                    }
                }
            }

            $frontier = $this->load($people, array_values($parentIds));
        }

        $parents = [];
        $genders = [];
        $spouses = [];

        foreach ($people as $person) {
            $genders[$person->id] = $person->gender;
            $parents[$person->id] = array_values(array_filter([$person->father_id, $person->mother_id], fn (?int $id): bool => $id !== null));

            if ($person->spouse_id !== null && isset($people[$person->spouse_id])) {
                $spouses[$person->id] = $person->spouse_id;
            }
        }

        $describe = new DescribeRelationship($parents, $genders, $spouses);

        return [
            'second_is' => $describe->between($first->id, $second->id),
            'first_is' => $describe->between($second->id, $first->id),
        ];
    }

    /**
     * Fetch residents not loaded yet and return their ids.
     *
     * @param  array<int, Resident>  $people
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function load(array &$people, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $loaded = [];

        foreach (Resident::query()->whereIn('id', $ids)->get(['id', 'gender', 'father_id', 'mother_id', 'spouse_id']) as $resident) {
            $people[$resident->id] = $resident;
            $loaded[] = $resident->id;
        }

        return $loaded;
    }
}
