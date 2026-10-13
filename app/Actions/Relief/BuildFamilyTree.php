<?php

namespace App\Actions\Relief;

use App\Enums\ResidentStatus;
use App\Models\Household;
use App\Models\Resident;

/**
 * Gathers the family around a household as graph data: its members, then their parents, spouses and
 * children, a few generations out in each direction. The size is capped so a very large family stays
 * readable and fast.
 *
 * @phpstan-type TreeNode array{id: int, name: string, gender: string, birth_year: int, status: string, barangay_name: string|null, household_code: string|null, in_household: bool, is_deceased: bool}
 * @phpstan-type TreeEdge array{from: int, to: int, kind: string}
 */
class BuildFamilyTree
{
    /** How many steps away from the household members the tree reaches. */
    private const DEPTH = 3;

    private const MAX_NODES = 120;

    /**
     * @return array{nodes: list<TreeNode>, edges: list<TreeEdge>, truncated: bool}
     */
    public function handle(Household $household): array
    {
        $memberIds = Resident::query()
            ->where('household_id', $household->id)
            ->whereNot('status', ResidentStatus::Archived->value)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $ids = array_fill_keys($memberIds, true);
        $frontier = $memberIds;
        $truncated = false;

        for ($depth = 0; $depth < self::DEPTH && $frontier !== []; $depth++) {
            $found = $this->neighbours(array_values($frontier));
            $frontier = [];

            foreach ($found as $id) {
                if (isset($ids[$id])) {
                    continue;
                }

                if (count($ids) >= self::MAX_NODES) {
                    $truncated = true;

                    break;
                }

                $ids[$id] = true;
                $frontier[] = $id;
            }
        }

        $residents = Resident::query()
            ->with(['barangay', 'household'])
            ->whereIn('id', array_keys($ids))
            ->whereNot('status', ResidentStatus::Archived->value)
            ->get()
            ->keyBy('id');

        return [
            'nodes' => array_values($residents->map(fn (Resident $resident): array => [
                'id' => $resident->id,
                'name' => $resident->full_name,
                'gender' => $resident->gender,
                'birth_year' => $resident->birthdate->year,
                'status' => $resident->status->value,
                'barangay_name' => $resident->barangay?->name,
                'household_code' => $resident->household?->household_code,
                'in_household' => $resident->household_id === $household->id,
                'is_deceased' => $resident->is_deceased,
            ])->all()),
            'edges' => $this->edges($residents->all()),
            'truncated' => $truncated,
        ];
    }

    /**
     * The people directly connected to the given residents: parents, spouses and children.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function neighbours(array $ids): array
    {
        $linked = Resident::query()
            ->whereIn('id', $ids)
            ->get(['id', 'father_id', 'mother_id', 'spouse_id'])
            ->flatMap(fn (Resident $resident): array => [$resident->father_id, $resident->mother_id, $resident->spouse_id])
            ->filter()
            ->map(fn ($id): int => (int) $id);

        $children = Resident::query()
            ->where(fn ($query) => $query->whereIn('father_id', $ids)->orWhereIn('mother_id', $ids))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        return array_values($linked->merge($children)->unique()->values()->all());
    }

    /**
     * Parent to child lines, and one line for each married pair.
     *
     * @param  array<int, Resident>  $residents
     * @return list<TreeEdge>
     */
    private function edges(array $residents): array
    {
        $edges = [];

        foreach ($residents as $resident) {
            foreach ([$resident->father_id, $resident->mother_id] as $parentId) {
                if ($parentId !== null && isset($residents[$parentId])) {
                    $edges[] = ['from' => $parentId, 'to' => $resident->id, 'kind' => 'parent'];
                }
            }

            if ($resident->spouse_id !== null && isset($residents[$resident->spouse_id]) && $resident->id < $resident->spouse_id) {
                $edges[] = ['from' => $resident->id, 'to' => $resident->spouse_id, 'kind' => 'spouse'];
            }
        }

        return $edges;
    }
}
