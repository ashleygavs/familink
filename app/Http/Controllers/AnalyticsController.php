<?php

namespace App\Http\Controllers;

use App\Enums\AllocationType;
use App\Enums\EventStatus;
use App\Enums\ResidentStatus;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\ReliefEvent;
use App\Models\ReliefLog;
use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @phpstan-type BarangayRow array{id: int, name: string, municipality_name: string|null, approved_residents: int, households: int, eligible: int, reached: int, claims: int, households_reached: int, coverage: int|null}
 * @phpstan-type HouseholdRow array{id: int, household_code: string, street_address: string, head_name: string|null, barangay_name: string, members: int|null, claimed: bool|null}
 */
class AnalyticsController extends Controller
{
    /**
     * How well relief reached the barangays the viewer may see: totals, one row per barangay, and a
     * searchable list of households that links to each family tree. Figures are for one chosen event.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $scope = $user->accessibleBarangayIds();

        $events = ReliefEvent::query()
            ->visibleTo($user)
            ->with('municipality')
            ->whereNot('status', EventStatus::Draft->value)
            ->latest('start_date')
            ->latest('id')
            ->get();

        $input = $request->validate([
            'event' => ['nullable', 'integer', Rule::in($events->pluck('id')->all())],
            'barangay' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $event = isset($input['event'])
            ? $events->firstWhere('id', (int) $input['event'])
            : ($events->first(fn (ReliefEvent $candidate) => $candidate->status === EventStatus::Active) ?? $events->first());

        // An event happens in one municipality, so only its barangays count towards it. Without an event,
        // everything the viewer may see is shown.
        $barangays = Barangay::query()
            ->with('municipality')
            ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope))
            ->when($event !== null, fn ($query) => $query->where('municipality_id', $event->municipality_id))
            ->orderBy('name')
            ->get();

        $rows = $this->barangayRows($barangays, $event);
        $barangayFilter = isset($input['barangay']) && $barangays->contains('id', (int) $input['barangay'])
            ? (int) $input['barangay']
            : null;

        return Inertia::render('reports/analytics', [
            'filters' => ['event' => $event?->id, 'barangay' => $barangayFilter, 'q' => $input['q'] ?? ''],
            'events' => $events->map(fn (ReliefEvent $candidate): array => [
                'id' => $candidate->id,
                'title' => $candidate->title,
                'municipality_name' => $candidate->municipality?->name,
                'province' => $candidate->municipality?->province,
                'status' => $candidate->status->value,
                'allocation_type' => $candidate->allocation_type->value,
                'max_claims_per_household' => $candidate->max_claims_per_household,
                'start_date' => $candidate->start_date->toDateString(),
                'end_date' => $candidate->end_date->toDateString(),
            ])->values(),
            'location' => $event === null ? null : [
                'municipality' => $event->municipality?->name,
                'province' => $event->municipality?->province,
                'barangays' => $barangays->pluck('name')->values()->all(),
            ],
            'totals' => $this->totals($rows, $event),
            'barangays' => $rows->values(),
            'households' => $this->households($barangays, $barangayFilter, $input['q'] ?? '', $event),
        ]);
    }

    /**
     * One summary row per barangay, built from a few grouped queries rather than one per barangay.
     *
     * @param  Collection<int, Barangay>  $barangays
     * @return Collection<int, BarangayRow>
     */
    private function barangayRows(Collection $barangays, ?ReliefEvent $event): Collection
    {
        $ids = $barangays->pluck('id')->all();
        $approved = ResidentStatus::Approved->value;

        $residents = Resident::query()
            ->where('status', $approved)
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
                'coverage' => $event === null ? null : $this->percent($reached, $eligible),
            ];
        });
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

    /**
     * @param  Collection<int, BarangayRow>  $rows
     * @return array<string, int|null>
     */
    private function totals(Collection $rows, ?ReliefEvent $event): array
    {
        $eligible = (int) $rows->sum('eligible');

        return [
            'approved_residents' => (int) $rows->sum('approved_residents'),
            'pending_residents' => Resident::query()
                ->where('status', ResidentStatus::Pending->value)
                ->whereIn('barangay_id', $rows->pluck('id')->all())
                ->count(),
            'households' => (int) $rows->sum('households'),
            'eligible' => $eligible,
            'claims' => (int) $rows->sum('claims'),
            'households_reached' => (int) $rows->sum('households_reached'),
            'coverage' => $event === null ? null : $this->percent((int) $rows->sum('reached'), $eligible),
        ];
    }

    private function percent(int $part, int $whole): ?int
    {
        return $whole === 0 ? null : (int) round($part / $whole * 100);
    }

    /**
     * Households of the visible barangays, searchable by code, street or head, with whether each one claimed
     * in the chosen event.
     *
     * @param  Collection<int, Barangay>  $barangays
     * @return LengthAwarePaginator<int, HouseholdRow>
     */
    private function households(Collection $barangays, ?int $barangayId, string $search, ?ReliefEvent $event): LengthAwarePaginator
    {
        $page = Household::query()
            ->with(['barangay', 'head'])
            ->withCount('residents')
            ->whereIn('barangay_id', $barangayId === null ? $barangays->pluck('id')->all() : [$barangayId])
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

                $query->where(fn ($inner) => $inner
                    ->where('household_code', 'like', $like)
                    ->orWhere('street_address', 'like', $like)
                    ->orWhereHas('head', fn ($head) => $head
                        ->whereRaw("concat_ws(' ', first_name, middle_name, last_name, suffix) like ?", [$like])));
            })
            ->orderBy('household_code')
            ->paginate(15)
            ->withQueryString();

        $claimed = $event === null ? collect() : ReliefLog::query()
            ->where('event_id', $event->id)
            ->whereIn('household_id', $page->pluck('id'))
            ->pluck('household_id')
            ->flip();

        return $page->through(fn (Household $household): array => [
            'id' => $household->id,
            'household_code' => $household->household_code,
            'street_address' => $household->street_address,
            'head_name' => $household->head?->full_name,
            'barangay_name' => $household->barangay->name,
            'members' => $household->residents_count,
            'claimed' => $event === null ? null : $claimed->has($household->id),
        ]);
    }
}
