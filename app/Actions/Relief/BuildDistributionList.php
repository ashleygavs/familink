<?php

namespace App\Actions\Relief;

use App\Enums\AllocationType;
use App\Enums\ResidentStatus;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\ReliefEvent;
use App\Models\ReliefLog;
use Illuminate\Support\Collection;

/**
 * The people of one barangay who can claim in an event, grouped by household, for a printed sheet with a signature
 * column. Each person or household shows whether it has claimed already.
 *
 * @phpstan-type Member array{system_id_no: string, name: string, age: int, claimed: bool, claimed_at: string|null, collected_by: string|null}
 * @phpstan-type HouseholdEntry array{code: string, street_address: string, head_name: string|null, claimed: bool, claimed_at: string|null, limit_reached: bool, members: list<Member>}
 */
class BuildDistributionList
{
    /**
     * @return array{households: list<HouseholdEntry>, totals: array{households: int, members: int, claimed: int}}
     */
    public function handle(ReliefEvent $event, Barangay $barangay, bool $onlyUnclaimed = false): array
    {
        $perHousehold = $event->allocation_type === AllocationType::PerHousehold;
        $limit = $event->claimLimitPerHousehold();

        $logs = ReliefLog::query()->where('event_id', $event->id)
            ->whereIn('household_id', Household::query()->where('barangay_id', $barangay->id)->select('id'))
            ->get();
        $byResident = $logs->keyBy('resident_id');
        $byHousehold = $logs->groupBy('household_id');

        $households = Household::query()
            ->with(['head', 'residents' => fn ($query) => $query
                ->where('status', ResidentStatus::Approved->value)
                ->where('is_deceased', false)
                ->orderBy('last_name')->orderBy('first_name')])
            ->where('barangay_id', $barangay->id)
            ->orderBy('household_code')
            ->get();

        $entries = [];
        $totals = ['households' => 0, 'members' => 0, 'claimed' => 0];

        foreach ($households as $household) {
            if ($household->residents->isEmpty()) {
                continue;
            }

            /** @var Collection<int, ReliefLog> $householdLogs */
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

            $totals['households']++;
            $totals['members'] += count($members);
            $totals['claimed'] += count(array_filter($members, fn (array $member): bool => $member['claimed']));

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

        return ['households' => $entries, 'totals' => $totals];
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
     * @var list<string>
     */
    public const HEADERS = [
        'Household', 'Address', 'Head of household', 'Resident ID', 'Name', 'Age', 'Already claimed', 'Claimed at',
        'Collected by', 'Note', 'Signature',
    ];
}
