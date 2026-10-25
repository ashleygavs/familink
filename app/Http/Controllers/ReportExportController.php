<?php

namespace App\Http\Controllers;

use App\Actions\Relief\BuildBarangayCoverage;
use App\Actions\Relief\BuildClaimsReport;
use App\Actions\Relief\BuildDistributionList;
use App\Actions\Relief\CsvExport;
use App\Enums\EventStatus;
use App\Models\Barangay;
use App\Models\ReliefEvent;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports that leave the system: the claims of an event and its barangay summary as spreadsheets, and the printable
 * list of who can claim in a barangay.
 *
 * @phpstan-import-type HouseholdEntry from BuildDistributionList
 */
class ReportExportController extends Controller
{
    /**
     * Every claim of an event, limited to the barangays the viewer may see.
     */
    public function claims(Request $request, ReliefEvent $event, BuildClaimsReport $report, CsvExport $csv): StreamedResponse
    {
        $this->authorizeEvent($request, $event);

        return $csv->download(
            $this->filename('claims', $event),
            BuildClaimsReport::HEADERS,
            $report->rows($event, $request->user()->accessibleBarangayIds()),
        );
    }

    /**
     * How well the event reached each barangay: the same figures as the analytics page.
     */
    public function coverage(Request $request, ReliefEvent $event, BuildBarangayCoverage $coverage, CsvExport $csv): StreamedResponse
    {
        $this->authorizeEvent($request, $event);

        $rows = $coverage->handle($this->barangays($request, $event), $event)
            ->map(fn (array $row): array => [
                $row['municipality_name'], $row['name'], $row['approved_residents'], $row['households'], $row['eligible'],
                $row['reached'], $row['claims'], $row['households_reached'], $row['coverage'] === null ? null : $row['coverage'].'%',
            ]);

        return $csv->download($this->filename('coverage', $event), [
            'Municipality', 'Barangay', 'Approved residents', 'Households', 'Eligible', 'Reached', 'Claims', 'Households reached', 'Coverage',
        ], $rows);
    }

    /**
     * The sheet to print for a distribution: the people of one barangay who can claim, with a signature column.
     */
    public function distributionList(Request $request, BuildDistributionList $builder): Response
    {
        ['events' => $events, 'event' => $event, 'barangays' => $barangays, 'barangay' => $barangay, 'onlyUnclaimed' => $onlyUnclaimed, 'search' => $search, 'all' => $all] = $this->listContext($request);

        // Printing everything on one sheet only makes sense up to a size. A bigger barangay prints by page.
        $notice = null;

        if ($all && $event !== null && $barangay !== null && $builder->countHouseholds($event, $barangay, $onlyUnclaimed) > BuildDistributionList::MAX_PRINT_HOUSEHOLDS) {
            $all = false;
            $notice = 'This barangay has too many households to print on one sheet. Print it page by page, or use the CSV.';
        }

        return Inertia::render('reports/distribution-list', [
            'filters' => ['event' => $event?->id, 'barangay' => $barangay?->id, 'only_unclaimed' => $onlyUnclaimed, 'q' => $all ? '' : $search, 'all' => $all],
            'notice' => $notice,
            'events' => $events->map(fn (ReliefEvent $candidate): array => [
                'id' => $candidate->id,
                'title' => $candidate->title,
                'status' => $candidate->status->value,
                'municipality_name' => $candidate->municipality?->name,
            ])->values(),
            'barangays' => $barangays->map(fn (Barangay $candidate): array => ['id' => $candidate->id, 'name' => $candidate->name])->values(),
            'event' => $event === null ? null : [
                'title' => $event->title,
                'allocation_type' => $event->allocation_type->value,
                'limit' => $event->claimLimitPerHousehold(),
                'start_date' => $event->start_date->toDateString(),
                'end_date' => $event->end_date->toDateString(),
                'municipality_name' => $event->municipality?->name,
            ],
            'barangay' => $barangay?->name,
            'list' => $event === null || $barangay === null ? null : [
                'households' => $all ? $this->wholeList($builder, $event, $barangay, $onlyUnclaimed) : $builder->page($event, $barangay, $onlyUnclaimed, $search),
                'totals' => $builder->totals($event, $barangay),
            ],
            'generated' => ['at' => now()->format('M j, Y g:i A'), 'by' => $request->user()->name],
        ]);
    }

    public function distributionListCsv(Request $request, BuildDistributionList $builder, CsvExport $csv): StreamedResponse
    {
        ['event' => $event, 'barangay' => $barangay, 'onlyUnclaimed' => $onlyUnclaimed] = $this->listContext($request);

        abort_if($event === null || $barangay === null, 404);

        return $csv->download($this->filename('distribution-list-'.Str::slug($barangay->name), $event), BuildDistributionList::HEADERS, $builder->flatten($builder->all($event, $barangay, $onlyUnclaimed)));
    }

    /**
     * Every household as one page-shaped list, so the screen can show the whole sheet with the same props as a page.
     *
     * @return LengthAwarePaginator<int, HouseholdEntry>
     */
    private function wholeList(BuildDistributionList $builder, ReliefEvent $event, Barangay $barangay, bool $onlyUnclaimed): LengthAwarePaginator
    {
        $entries = $builder->all($event, $barangay, $onlyUnclaimed);

        return new LengthAwarePaginator($entries, count($entries), max(count($entries), 1), 1);
    }

    /**
     * The events, barangays and filters of the printable list. Only events that are not drafts, and barangays of
     * the event's municipality that the viewer may see.
     *
     * @return array{events: Collection<int, ReliefEvent>, event: ReliefEvent|null, barangays: Collection<int, Barangay>, barangay: Barangay|null, onlyUnclaimed: bool, search: string, all: bool}
     */
    private function listContext(Request $request): array
    {
        $user = $request->user();

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
            'only_unclaimed' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'all' => ['nullable', 'boolean'],
        ]);

        $event = isset($input['event'])
            ? $events->firstWhere('id', (int) $input['event'])
            : ($events->first(fn (ReliefEvent $candidate) => $candidate->status === EventStatus::Active) ?? $events->first());

        $barangays = $event === null ? collect() : $this->barangays($request, $event);
        $barangay = isset($input['barangay'])
            ? $barangays->firstWhere('id', (int) $input['barangay'])
            : ($barangays->firstWhere('id', $user->barangay_id) ?? $barangays->first());

        return [
            'events' => $events,
            'event' => $event,
            'barangays' => $barangays,
            'barangay' => $barangay ?? $barangays->first(),
            'onlyUnclaimed' => (bool) ($input['only_unclaimed'] ?? false),
            'search' => trim((string) ($input['q'] ?? '')),
            'all' => (bool) ($input['all'] ?? false),
        ];
    }

    /**
     * The barangays the viewer may see in the municipality where the event happens.
     *
     * @return Collection<int, Barangay>
     */
    private function barangays(Request $request, ReliefEvent $event): Collection
    {
        $scope = $request->user()->accessibleBarangayIds();

        return Barangay::query()
            ->with('municipality')
            ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope))
            ->where('municipality_id', $event->municipality_id)
            ->orderBy('name')
            ->get();
    }

    private function authorizeEvent(Request $request, ReliefEvent $event): void
    {
        abort_unless(ReliefEvent::query()->visibleTo($request->user())->whereKey($event->id)->exists(), 404);
    }

    private function filename(string $kind, ReliefEvent $event): string
    {
        return Str::slug($event->title).'-'.$kind.'-'.now()->format('Y-m-d').'.csv';
    }
}
