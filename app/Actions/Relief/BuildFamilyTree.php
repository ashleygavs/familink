<?php

namespace App\Actions\Relief;

use App\Enums\ResidentStatus;
use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentChange;

/**
 * Gathers the family around a household as graph data: its members, then their parents, spouses and children, a few
 * generations out in each direction. How far it reaches depends on the chosen level. Beyond the close family only
 * parents and children are followed, so the tree grows along blood lines and not into every in-law's family. The size
 * is capped so a very large family stays readable and fast.
 *
 * @phpstan-type TreeNode array{id: int, name: string, gender: string, birth_year: int, status: string, barangay_name: string|null, household_code: string|null, in_household: bool, is_deceased: bool, relation: string|null, relation_group: string|null, relation_rank: int|null, marriage_notes: list<string>}
 * @phpstan-type TreeEdge array{from: int, to: int, kind: string, status?: string}
 */
class BuildFamilyTree
{
    /**
     * How many steps each level reaches from the people of the household. Close family goes as far as aunts, uncles
     * and great-grandparents, the next level adds first cousins, and the last adds second cousins.
     *
     * @var array<string, int>
     */
    public const REACHES = ['close' => 3, 'cousins' => 4, 'wide' => 6];

    /** Up to this many steps from the household, spouses are followed too, as in-laws are part of the close family. */
    private const CLOSE_DEPTH = 3;

    private const MAX_NODES = 120;

    /** The wider levels reach more people, so they are allowed a larger tree. */
    private const MAX_NODES_WIDE = 250;

    /**
     * @return array{nodes: list<TreeNode>, edges: list<TreeEdge>, truncated: bool, truncated_relatives: bool, reference: array{id: int, name: string}|null, reach: string, added: int}
     */
    public function handle(Household $household, string $reach = 'close'): array
    {
        $reach = array_key_exists($reach, self::REACHES) ? $reach : 'close';
        $steps = self::REACHES[$reach];
        $limit = $steps > self::CLOSE_DEPTH ? self::MAX_NODES_WIDE : self::MAX_NODES;

        $memberIds = array_values(Resident::query()
            ->where('household_id', $household->id)
            ->whereNot('status', ResidentStatus::Archived->value)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all());

        $ids = array_fill_keys($memberIds, true);
        // How many steps from the household each person was found, so a level can say how many people it added.
        $stepsAway = array_fill_keys($memberIds, 0);
        $frontier = $memberIds;
        $truncated = false;
        $truncatedRelatives = false;

        for ($depth = 0; $depth < $steps && $frontier !== []; $depth++) {
            ['follow' => $follow, 'leaves' => $leaves] = $this->neighbours($frontier, $depth >= self::CLOSE_DEPTH);
            $frontier = [];

            foreach ([[$follow, true], [$leaves, false]] as [$found, $expands]) {
                foreach ($found as $id) {
                    if (isset($ids[$id])) {
                        continue;
                    }

                    if (count($ids) >= $limit) {
                        $truncated = true;
                        // Spouses past the close family are cut first. Only when relatives themselves are cut does the
                        // page need to say that some of the family is missing.
                        $truncatedRelatives = $truncatedRelatives || $expands;

                        break 2;
                    }

                    $ids[$id] = true;
                    $stepsAway[$id] = $depth + 1;

                    if ($expands) {
                        $frontier[] = $id;
                    }
                }
            }
        }

        $residents = Resident::query()
            ->with(['barangay', 'household'])
            ->whereIn('id', array_keys($ids))
            ->whereNot('status', ResidentStatus::Archived->value)
            ->get()
            ->keyBy('id');

        $reference = $this->reference($household, $memberIds, $residents->all());
        $describe = $this->describer($residents->all());

        $relationship = fn (Resident $resident): ?array => $reference === null ? null : $describe->classify($reference->id, $resident->id);

        ['pairs' => $endedPairs, 'notes' => $endedNotes] = $this->endedMarriages($residents->all());
        $widowedNotes = $this->widowedNotes($residents->all());

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
                'relation' => $relationship($resident)['label'] ?? null,
                'relation_group' => $relationship($resident)['group'] ?? null,
                'relation_rank' => $relationship($resident)['rank'] ?? null,
                'marriage_notes' => [...($widowedNotes[$resident->id] ?? []), ...($endedNotes[$resident->id] ?? [])],
            ])->all()),
            'edges' => $this->edges($residents->all(), $endedPairs),
            'truncated' => $truncated,
            'truncated_relatives' => $truncatedRelatives,
            'reference' => $reference === null ? null : ['id' => $reference->id, 'name' => $reference->full_name],
            'reach' => $reach,
            'added' => $this->addedBy($reach, $residents->keys()->all(), $stepsAway),
        ];
    }

    /**
     * How many of the people shown were reached only because of this level, and not by the level before it. The close
     * family is the first level, so it adds nobody by this count.
     *
     * @param  array<int, int|string>  $shown
     * @param  array<int, int>  $stepsAway
     */
    private function addedBy(string $reach, array $shown, array $stepsAway): int
    {
        $previous = ['cousins' => self::REACHES['close'], 'wide' => self::REACHES['cousins']][$reach] ?? null;

        if ($previous === null) {
            return 0;
        }

        return count(array_filter($shown, fn (int|string $id): bool => ($stepsAway[(int) $id] ?? 0) > $previous));
    }

    /**
     * The people directly connected to the given residents: parents, spouses and children. Past the close family a
     * spouse is only shown, not followed any further, so the family of someone who married in does not take over the tree.
     *
     * @param  list<int>  $ids
     * @return array{follow: list<int>, leaves: list<int>}
     */
    private function neighbours(array $ids, bool $bloodOnly): array
    {
        $linked = Resident::query()->whereIn('id', $ids)->get(['id', 'father_id', 'mother_id', 'spouse_id']);

        $parents = $linked->flatMap(fn (Resident $resident): array => [$resident->father_id, $resident->mother_id]);
        $spouses = $linked->pluck('spouse_id');

        $children = Resident::query()
            ->where(fn ($query) => $query->whereIn('father_id', $ids)->orWhereIn('mother_id', $ids))
            ->pluck('id');

        $unique = fn ($found): array => array_values($found->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return $bloodOnly
            ? ['follow' => $unique($parents->merge($children)), 'leaves' => $unique($spouses)]
            : ['follow' => $unique($parents->merge($spouses)->merge($children)), 'leaves' => []];
    }

    /**
     * The person the relationship labels are given from: the head of the household, or the first member without one.
     *
     * @param  list<int>  $memberIds
     * @param  array<int, Resident>  $residents
     */
    private function reference(Household $household, array $memberIds, array $residents): ?Resident
    {
        if ($household->head_resident_id !== null && isset($residents[$household->head_resident_id])) {
            return $residents[$household->head_resident_id];
        }

        foreach ($memberIds as $id) {
            if (isset($residents[$id])) {
                return $residents[$id];
            }
        }

        return null;
    }

    /**
     * @param  array<int, Resident>  $residents
     */
    private function describer(array $residents): DescribeRelationship
    {
        $parents = [];
        $genders = [];
        $spouses = [];

        foreach ($residents as $resident) {
            $genders[$resident->id] = $resident->gender;
            $parents[$resident->id] = array_values(array_filter(
                [$resident->father_id, $resident->mother_id],
                fn (?int $parentId): bool => $parentId !== null && isset($residents[$parentId]),
            ));

            if ($resident->spouse_id !== null && isset($residents[$resident->spouse_id])) {
                $spouses[$resident->id] = $resident->spouse_id;
            }
        }

        return new DescribeRelationship($parents, $genders, $spouses);
    }

    /**
     * Parent to child lines, one line for each married pair, and one for each marriage that has ended. A pair is
     * drawn once even when only one of them still points to the other, which happens when a widowed person remarries.
     *
     * @param  array<int, Resident>  $residents
     * @param  array<string, string>  $ended  The ended marriages, by the pair's ids joined with a dash, and how each ended.
     * @return list<TreeEdge>
     */
    private function edges(array $residents, array $ended): array
    {
        $edges = [];
        $married = [];

        foreach ($residents as $resident) {
            foreach ([$resident->father_id, $resident->mother_id] as $parentId) {
                if ($parentId !== null && isset($residents[$parentId])) {
                    $edges[] = ['from' => $parentId, 'to' => $resident->id, 'kind' => 'parent'];
                }
            }

            if ($resident->spouse_id !== null && isset($residents[$resident->spouse_id])) {
                $married[$this->pairKey($resident->id, $resident->spouse_id)] = true;
            }
        }

        foreach (array_keys($married) as $key) {
            [$from, $to] = array_map('intval', explode('-', $key));
            $edges[] = ['from' => $from, 'to' => $to, 'kind' => 'spouse'];
        }

        foreach ($ended as $key => $how) {
            [$from, $to] = array_map('intval', explode('-', $key));
            $edges[] = ['from' => $from, 'to' => $to, 'kind' => 'ended', 'status' => $how];
        }

        return $edges;
    }

    private function pairKey(int $first, int $second): string
    {
        return min($first, $second).'-'.max($first, $second);
    }

    /**
     * The marriages of the people shown that have ended, as recorded in the history when the marriage was ended. A pair
     * that is married again is left out, and only the latest ending of a pair counts. Both people must be on the tree, so
     * an ex-spouse is never what brings someone into it.
     *
     * @param  array<int, Resident>  $residents
     * @return array{pairs: array<string, string>, notes: array<int, list<string>>}
     */
    private function endedMarriages(array $residents): array
    {
        $pairs = [];
        $notes = [];

        $changes = ResidentChange::query()
            ->where('type', ResidentChange::MARRIAGE_ENDED)
            ->whereIn('resident_id', array_keys($residents))
            ->orderBy('id')
            ->get(['resident_id', 'data']);

        foreach ($changes as $change) {
            $person = $residents[$change->resident_id] ?? null;
            $partner = $residents[(int) ($change->data['spouse_id'] ?? 0)] ?? null;
            $how = $change->data['civil_status'] ?? null;

            if ($person === null || $partner === null || ! in_array($how, ['Separated', 'Annulled'], true)) {
                continue;
            }

            if ($person->spouse_id === $partner->id || $partner->spouse_id === $person->id) {
                unset($notes[$person->id][$partner->id]);

                continue;
            }

            $pairs[$this->pairKey($person->id, $partner->id)] = $how;
            $notes[$person->id][$partner->id] = $how === 'Annulled'
                ? "Marriage to {$partner->full_name} was annulled."
                : "Separated from {$partner->full_name}.";
        }

        return [
            'pairs' => $pairs,
            'notes' => array_map(fn (array $lines): array => array_values($lines), $notes),
        ];
    }

    /**
     * "Widow of" and "Widower of" for the living spouse of someone who has died. It is read from the side of the person
     * who died, so it still shows when the widowed person has married again.
     *
     * @param  array<int, Resident>  $residents
     * @return array<int, list<string>>
     */
    private function widowedNotes(array $residents): array
    {
        $notes = [];

        foreach ($residents as $deceased) {
            $survivor = $deceased->spouse_id === null ? null : ($residents[$deceased->spouse_id] ?? null);

            if (! $deceased->is_deceased || $survivor === null || $survivor->is_deceased) {
                continue;
            }

            $word = $survivor->gender === 'Male' ? 'Widower' : 'Widow';
            $notes[$survivor->id][] = "{$word} of {$deceased->full_name}.";
        }

        return $notes;
    }
}
