<?php

namespace App\Actions\Relief;

use App\Enums\ResidentStatus;
use App\Models\Household;
use App\Models\HouseholdReview;
use App\Models\ReliefEvent;
use App\Models\ReliefLog;
use App\Models\Resident;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds pairs of households whose members are family, which is how one household can end up claiming twice: a couple
 * recorded in two households, or a young child living apart from a parent. It only points at them. Whether a pair is a
 * real split is for an admin to decide, and the decision is kept as a review so the pair stops being listed.
 *
 * @phpstan-type Side array{id: int, household_code: string, barangay_name: string|null, head_name: string|null, members: int, can_open: bool, claims: int|null}
 * @phpstan-type SplitCase array{key: string, households: list<Side>, reasons: list<string>, both_claimed: bool|null, review: array{id: int, reason: string, reviewed_by: string|null, reviewed_on: string}|null}
 */
class FindSplitHouseholds
{
    /** A child younger than this is expected to live with a parent. */
    private const CHILD_AGE = 18;

    /**
     * @param  list<int>|null  $barangayIds  The barangays the viewer may see, or null for all. A pair is listed when at
     *                                       least one of its households is in them.
     * @return list<SplitCase>
     */
    public function handle(?array $barangayIds, ?ReliefEvent $event = null): array
    {
        /** @var array<string, array{pair: array{first_household_id: int, second_household_id: int}, reasons: array<string, true>, residents: array<int, true>}> $found */
        $found = [];

        foreach ($this->couples($barangayIds) as $row) {
            $this->note($found, $row['a_household'], $row['b_household'], [$row['a_id'], $row['b_id']], [
                'couple', min($row['a_id'], $row['b_id']), max($row['a_id'], $row['b_id']),
            ]);
        }

        foreach ($this->children($barangayIds) as $row) {
            $this->note($found, $row['child_household'], $row['parent_household'], [$row['child_id'], $row['parent_id']], [
                'child', $row['child_id'], $row['parent_id'], $row['parent_id'] === $row['father_id'] ? 'father' : 'mother',
            ]);
        }

        if ($found === []) {
            return [];
        }

        $people = Resident::query()
            ->whereIn('id', array_keys(array_replace(...array_map(fn (array $case): array => $case['residents'], array_values($found)))))
            ->get()
            ->keyBy('id');

        $householdIds = array_values(array_unique(array_merge(...array_map(
            fn (array $case): array => array_values($case['pair']),
            array_values($found),
        ))));

        $households = Household::query()
            ->with(['barangay', 'head'])
            ->withCount('residents')
            ->whereIn('id', $householdIds)
            ->get()
            ->keyBy('id');

        $claims = $event === null ? null : ReliefLog::query()
            ->where('event_id', $event->id)
            ->whereIn('household_id', $householdIds)
            ->selectRaw('household_id, count(*) as total')
            ->groupBy('household_id')
            ->pluck('total', 'household_id');

        $reviews = HouseholdReview::query()
            ->with('reviewedBy')
            ->whereIn('first_household_id', $householdIds)
            ->whereIn('second_household_id', $householdIds)
            ->get()
            ->keyBy(fn (HouseholdReview $review): string => $review->first_household_id.'-'.$review->second_household_id);

        $cases = [];

        foreach ($found as $key => $case) {
            $sides = [];

            foreach ($case['pair'] as $id) {
                $household = $households->get($id);

                if ($household === null) {
                    continue 2;
                }

                $canOpen = $barangayIds === null || in_array($household->barangay_id, $barangayIds, true);

                $sides[] = [
                    'id' => $household->id,
                    'household_code' => $household->household_code,
                    'barangay_name' => $household->barangay->name,
                    'head_name' => $canOpen ? $household->head?->full_name : null,
                    'members' => $household->residents_count,
                    'can_open' => $canOpen,
                    'claims' => $claims === null ? null : (int) ($claims[$id] ?? 0),
                ];
            }

            $review = $reviews->get($key);

            $cases[] = [
                'key' => $key,
                'households' => $sides,
                'reasons' => array_map(fn (string $reason): string => $this->sentence(explode('|', $reason), $people), array_keys($case['reasons'])),
                'both_claimed' => $claims === null ? null : ($sides[0]['claims'] ?? 0) > 0 && ($sides[1]['claims'] ?? 0) > 0,
                'review' => $review === null ? null : [
                    'id' => $review->id,
                    'reason' => $review->reason,
                    'reviewed_by' => $review->reviewedBy?->name,
                    'reviewed_on' => $review->created_at?->toDateString() ?? '',
                ],
            ];
        }

        usort($cases, fn (array $a, array $b): int => [$b['both_claimed'] ? 1 : 0, count($b['reasons']), $a['households'][0]['household_code']]
            <=> [$a['both_claimed'] ? 1 : 0, count($a['reasons']), $b['households'][0]['household_code']]);

        return $cases;
    }

    /**
     * Married people whose two records are in different households.
     *
     * @param  list<int>|null  $barangayIds
     * @return list<array{a_id: int, b_id: int, a_household: int, b_household: int}>
     */
    private function couples(?array $barangayIds): array
    {
        return array_values($this->active(DB::table('residents as a')->join('residents as b', 'b.id', '=', 'a.spouse_id'), ['a', 'b'])
            ->whereColumn('a.household_id', '<>', 'b.household_id')
            ->when($barangayIds !== null, fn (Builder $query) => $query
                ->where(fn (Builder $inner) => $inner->whereIn('a.barangay_id', $barangayIds)->orWhereIn('b.barangay_id', $barangayIds)))
            ->get(['a.id as a_id', 'b.id as b_id', 'a.household_id as a_household', 'b.household_id as b_household'])
            ->map(fn (object $row): array => [
                'a_id' => (int) $row->a_id, 'b_id' => (int) $row->b_id,
                'a_household' => (int) $row->a_household, 'b_household' => (int) $row->b_household,
            ])
            ->all());
    }

    /**
     * Young children who live in a different household from a parent.
     *
     * @param  list<int>|null  $barangayIds
     * @return list<array{child_id: int, parent_id: int, father_id: int|null, child_household: int, parent_household: int}>
     */
    private function children(?array $barangayIds): array
    {
        $parents = DB::table('residents as c')
            ->join('residents as p', fn ($join) => $join->on('p.id', '=', 'c.father_id')->orOn('p.id', '=', 'c.mother_id'));

        return array_values($this->active($parents, ['c', 'p'])
            ->where('c.birthdate', '>', now()->subYears(self::CHILD_AGE)->toDateString())
            ->whereColumn('c.household_id', '<>', 'p.household_id')
            ->when($barangayIds !== null, fn (Builder $query) => $query
                ->where(fn (Builder $inner) => $inner->whereIn('c.barangay_id', $barangayIds)->orWhereIn('p.barangay_id', $barangayIds)))
            ->get(['c.id as child_id', 'p.id as parent_id', 'c.father_id as father_id', 'c.household_id as child_household', 'p.household_id as parent_household'])
            ->map(fn (object $row): array => [
                'child_id' => (int) $row->child_id, 'parent_id' => (int) $row->parent_id,
                'father_id' => $row->father_id === null ? null : (int) $row->father_id,
                'child_household' => (int) $row->child_household, 'parent_household' => (int) $row->parent_household,
            ])
            ->all());
    }

    /**
     * Only people who can claim relief count: approved and living.
     *
     * @param  list<string>  $aliases
     */
    private function active(Builder $query, array $aliases): Builder
    {
        foreach ($aliases as $alias) {
            $query->where("{$alias}.status", ResidentStatus::Approved->value)->where("{$alias}.is_deceased", false);
        }

        return $query;
    }

    /**
     * Record that two households are linked by a reason, once per pair and reason.
     *
     * @param  array<string, array{pair: array{first_household_id: int, second_household_id: int}, reasons: array<string, true>, residents: array<int, true>}>  $found
     * @param  list<int>  $residentIds
     * @param  list<int|string>  $reason
     */
    private function note(array &$found, int $one, int $other, array $residentIds, array $reason): void
    {
        $pair = HouseholdReview::pair($one, $other);
        $key = $pair['first_household_id'].'-'.$pair['second_household_id'];

        $found[$key] ??= ['pair' => $pair, 'reasons' => [], 'residents' => []];
        $found[$key]['reasons'][implode('|', $reason)] = true;

        foreach ($residentIds as $id) {
            $found[$key]['residents'][$id] = true;
        }
    }

    /**
     * @param  list<string>  $reason
     * @param  Collection<int, Resident>  $people
     */
    private function sentence(array $reason, Collection $people): string
    {
        if ($reason[0] === 'couple') {
            return ($people[(int) $reason[1]]->full_name ?? 'Someone').' and '.($people[(int) $reason[2]]->full_name ?? 'someone').' are married but live in different households.';
        }

        $child = $people[(int) $reason[1]] ?? null;
        $parent = $people[(int) $reason[2]] ?? null;

        return ($child->full_name ?? 'A child').', age '.($child?->birthdate->age ?? '?').', lives apart from their '.$reason[3].', '.($parent->full_name ?? 'a parent').'.';
    }
}
