<?php

namespace App\Actions\Relief;

use App\Enums\AllocationType;
use App\Enums\ResidentStatus;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\ReliefEvent;
use App\Models\ReliefLog;
use App\Models\Resident;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How well relief reached each barangay for one event: who is eligible, who claimed and the coverage. Used by the
 * analytics page and by the coverage export, so both always show the same figures.
 *
 * @phpstan-type BarangayRow array{id: int, name: string, municipality_name: string|null, approved_residents: int, households: int, eligible: int, reached: int, claims: int, households_reached: int, coverage: int|null}
 */
class BuildBarangayCoverage
{
    /**
     * One summary row per barangay, built from a few grouped queries rather than one per barangay.
     *
     * @param  Collection<int, Barangay>  $barangays
     * @return Collection<int, BarangayRow>
     */
    public function handle(Collection $barangays, ?ReliefEvent $event): Collection
    {
        $ids = $barangays->pluck('id')->all();
        $approved = ResidentStatus::Approved->value;

        $residents = Resident::query()
            ->where('status', $approved)
            ->where('is_deceased', false)
            ->whereIn('barangay_id', $ids)
            ->selectRaw('barangay_id, count(*) as residents, count(distinct household_id) as households_with_residents')
            ->groupBy('barangay_id')
            ->get()
            ->keyBy('barangay_id');

        $households = Household::query()
            ->whereIn('barangay_id', $ids)
            ->selectRaw('barangay_id, count(*) as total')
            ->groupBy('barangay_id')
            ->pluck('total', 'barangay_id');

        $claims = $event === null ? collect() : ReliefLog::query()
            ->join('residents', 'residents.id', '=', 'relief_logs.resident_id')
            ->where('relief_logs.event_id', $event->id)
            ->whereIn('residents.barangay_id', $ids)
            ->selectRaw('residents.barangay_id as barangay_id, count(*) as claims, count(distinct relief_logs.household_id) as households_reached')
            ->groupBy('residents.barangay_id')
            ->get()
            ->keyBy('barangay_id');

        $perHousehold = $event?->allocation_type === AllocationType::PerHousehold;
        $cap = $perHousehold ? null : $event?->max_claims_per_household;
        $capped = $cap === null ? collect() : $this->cappedEligible(array_values($ids), $cap);

        return $barangays->map(function (Barangay $barangay) use ($residents, $households, $claims, $capped, $perHousehold, $event): array {
            $approvedResidents = (int) ($residents[$barangay->id]->residents ?? 0);
            $eligible = $perHousehold ? (int) ($residents[$barangay->id]->households_with_residents ?? 0) : (int) ($capped[$barangay->id] ?? $approvedResidents);
            $claimCount = (int) ($claims[$barangay->id]->claims ?? 0);
            $reached = $perHousehold ? (int) ($claims[$barangay->id]->households_reached ?? 0) : $claimCount;

            return [
                'id' => $barangay->id,
                'name' => $barangay->name,
                'municipality_name' => $barangay->municipality?->name,
                'approved_residents' => $approvedResidents,
                'households' => (int) ($households[$barangay->id] ?? 0),
                'eligible' => $eligible,
                'reached' => $reached,
                'claims' => $claimCount,
                'households_reached' => (int) ($claims[$barangay->id]->households_reached ?? 0),
                'coverage' => $event === null ? null : self::percent($reached, $eligible),
            ];
        });
    }

    public static function percent(int $part, int $whole): ?int
    {
        return $whole === 0 ? null : (int) round($part / $whole * 100);
    }

    /**
     * Residents who can still claim when a household is limited to $cap claims: each household counts at most
     * $cap approved residents.
     *
     * @param  list<int>  $barangayIds
     * @return Collection<int, int>
     */
    private function cappedEligible(array $barangayIds, int $cap): Collection
    {
        $perHousehold = Resident::query()
            ->where('status', ResidentStatus::Approved->value)
            ->where('is_deceased', false)
            ->whereIn('barangay_id', $barangayIds)
            ->selectRaw('barangay_id, household_id, count(*) as members')
            ->groupBy('barangay_id', 'household_id');

        return DB::query()
            ->fromSub($perHousehold, 'households')
            ->selectRaw('barangay_id, sum(case when members > ? then ? else members end) as eligible', [$cap, $cap])
            ->groupBy('barangay_id')
            ->pluck('eligible', 'barangay_id')
            ->map(fn ($eligible): int => (int) $eligible);
    }
}
