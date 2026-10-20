<?php

namespace App\Actions\Relief;

use App\Enums\AllocationType;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\ReliefEvent;
use App\Models\ReliefLog;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The people of one barangay who can claim in an event, grouped by household, for a printed sheet with a signature
 * column. Each person or household shows whether it has claimed already. Searching and paging happen in the database,
 * so the list stays fast when a barangay has thousands of residents.
 *
 * @phpstan-type Member array{system_id_no: string, name: string, age: int, claimed: bool, claimed_at: string|null, collected_by: string|null}
 * @phpstan-type HouseholdEntry array{code: string, street_address: string, head_name: string|null, claimed: bool, claimed_at: string|null, limit_reached: bool, members: list<Member>}
 */
class BuildDistributionList
{
    /** Households shown on one page. */
    public const PER_PAGE = 10;

    /** The most households that "print all" will put on one sheet. A bigger barangay prints by page. */
    public const MAX_PRINT_HOUSEHOLDS = 1000;

    /**
     * @var list<string>
     */
    public const HEADERS = [
        'Household', 'Address', 'Head of household', 'Resident ID', 'Name', 'Age', 'Already claimed', 'Claimed at',
        'Collected by', 'Note', 'Signature',
    ];

    /**
     * One page of households, the way they are shown and printed by page.
     *
     * @return LengthAwarePaginator<int, HouseholdEntry>
     */
    public function page(ReliefEvent $event, Barangay $barangay, bool $onlyUnclaimed = false, string $search = ''): LengthAwarePaginator
    {
        $page = $this->households($event, $barangay, $onlyUnclaimed, $search)->paginate(self::PER_PAGE)->withQueryString();

        /** @var Collection<int, Household> $households */
        $households = $page->getCollection();

        return $page->setCollection(collect($this->entries($households, $event, $onlyUnclaimed)));
    }

    /**
     * Every household in one list, for printing the whole sheet. Search is not applied: it only narrows the screen.
     *
     * @return list<HouseholdEntry>
     */
    public function all(ReliefEvent $event, Barangay $barangay, bool $onlyUnclaimed = false): array
    {
        return $this->entries($this->households($event, $barangay, $onlyUnclaimed)->get(), $event, $onlyUnclaimed);
    }

    /**
     * How many households a "print all" would hold.
     */
    public function countHouseholds(ReliefEvent $event, Barangay $barangay, bool $onlyUnclaimed = false): int
    {
        return $this->households($event, $barangay, $onlyUnclaimed)->count();
    }

    /**
     * The figures of the whole barangay for this event, whatever the search or filter on the screen.
     *
     * @return array{households: int, members: int, claimed: int}
     */
    public function totals(ReliefEvent $event, Barangay $barangay): array
    {
        $inBarangay = Household::query()->where('barangay_id', $barangay->id)->select('id');
        $eligible = fn () => Resident::query()->eligibleForRelief()->whereIn('household_id', $inBarangay);

        $claimed = $event->allocation_type === AllocationType::PerHousehold
            ? $eligible()->whereIn('household_id', ReliefLog::query()->where('event_id', $event->id)->select('household_id'))->count()
            : $eligible()->whereIn('id', ReliefLog::query()->where('event_id', $event->id)->select('resident_id'))->count();

        return [
            'households' => $eligible()->distinct()->count('household_id'),
            'members' => $eligible()->count(),
            'claimed' => $claimed,
        ];
    }

    /**
     * The same list as flat rows for a spreadsheet.
     *
     * @param  list<HouseholdEntry>  $households
     * @return list<list<string|int|null>>
     */
    public function flatten(array $households): array
    {
        $rows = [];

        foreach ($households as $household) {
            foreach ($household['members'] as $member) {
                $rows[] = [
                    $household['code'], $household['street_address'], $household['head_name'], $member['system_id_no'], $member['name'],
                    $member['age'], $member['claimed'] ? 'Yes' : 'No', $member['claimed_at'], $member['collected_by'],
                    $household['limit_reached'] && ! $member['claimed'] ? 'Household limit reached' : null, '',
                ];
            }
        }

        return $rows;
    }

    /**
     * The households of the barangay with at least one person who can claim, narrowed by a search and by "not
     * claimed yet", in household code order.
     *
     * @return Builder<Household>
     */
    private function households(ReliefEvent $event, Barangay $barangay, bool $onlyUnclaimed, string $search = ''): Builder
    {
        $search = trim($search);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        return Household::query()
            ->with(['head', 'residents' => fn ($query) => $query->eligibleForRelief()->orderBy('last_name')->orderBy('first_name')])
            ->where('barangay_id', $barangay->id)
            ->whereHas('residents', fn ($residents) => $residents->eligibleForRelief())
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('household_code', 'like', $like)
                ->orWhere('street_address', 'like', $like)
                ->orWhereHas('residents', fn ($residents) => $residents->eligibleForRelief()->where(fn ($person) => $person
                    ->whereRaw("concat_ws(' ', first_name, middle_name, last_name, suffix) like ?", [$like])
                    ->orWhere('system_id_no', 'like', $like)))))
            ->when($onlyUnclaimed, fn ($query) => $this->withoutClaim($query, $event))
            ->orderBy('household_code');
    }

    /**
     * Only households that can still claim: a Per Household event not yet claimed, or a Per Resident event where
     * someone has not claimed and the household has not used up its limit.
     *
     * @param  Builder<Household>  $query
     */
    private function withoutClaim(Builder $query, ReliefEvent $event): void
    {
        if ($event->allocation_type === AllocationType::PerHousehold) {
            $query->whereDoesntHave('claims', fn ($claims) => $claims->where('event_id', $event->id));

            return;
        }

        $query->whereHas('residents', fn ($residents) => $residents->eligibleForRelief()
            ->whereDoesntHave('claims', fn ($claims) => $claims->where('event_id', $event->id)));

        $limit = $event->claimLimitPerHousehold();

        if ($limit !== null) {
            $query->whereRaw(
                '(select count(*) from relief_logs where relief_logs.household_id = households.id and relief_logs.event_id = ?) < ?',
                [$event->id, $limit],
            );
        }
    }

    /**
     * The rows of the given households, with who has claimed and when.
     *
     * @param  Collection<int, Household>  $households
     * @return list<HouseholdEntry>
     */
    private function entries(Collection $households, ReliefEvent $event, bool $onlyUnclaimed): array
    {
        $perHousehold = $event->allocation_type === AllocationType::PerHousehold;
        $limit = $event->claimLimitPerHousehold();

        $logs = ReliefLog::query()->where('event_id', $event->id)->whereIn('household_id', $households->modelKeys())->get();
        $byResident = $logs->keyBy('resident_id');
        $byHousehold = $logs->groupBy('household_id');

        $entries = [];

        foreach ($households as $household) {
            $householdLogs = $byHousehold->get($household->id, collect());
            $householdClaimed = $householdLogs->isNotEmpty();
            $limitReached = ! $perHousehold && $limit !== null && $householdLogs->count() >= $limit;

            $members = [];

            foreach ($household->residents as $resident) {
                $log = $perHousehold ? $householdLogs->first() : $byResident->get($resident->id);
                $claimed = $perHousehold ? $householdClaimed : $log !== null;

                if ($onlyUnclaimed && ($claimed || $limitReached)) {
                    continue;
                }

                $members[] = [
                    'system_id_no' => $resident->system_id_no,
                    'name' => $resident->full_name,
                    'age' => $resident->birthdate->age,
                    'claimed' => $claimed,
                    'claimed_at' => $claimed ? $log?->created_at?->format('M j, Y g:i A') : null,
                    'collected_by' => $claimed && $log?->claimed_by_representative ? $log->representative_name : null,
                ];
            }

            if ($members === []) {
                continue;
            }

            $entries[] = [
                'code' => $household->household_code,
                'street_address' => $household->street_address,
                'head_name' => $household->head?->full_name,
                'claimed' => $householdClaimed,
                'claimed_at' => $householdLogs->first()?->created_at?->format('M j, Y g:i A'),
                'limit_reached' => $limitReached,
                'members' => $members,
            ];
        }

        return $entries;
    }
}
