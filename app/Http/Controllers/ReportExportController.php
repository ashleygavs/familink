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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports that leave the system: the claims of an event and its barangay summary as spreadsheets, and the printable
 * list of who can claim in a barangay.
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
        ['events' => $events, 'event' => $event, 'barangays' => $barangays, 'barangay' => $barangay, 'onlyUnclaimed' => $onlyUnclaimed] = $this->listContext($request);

        return Inertia::render('reports/distribution-list', [
            'filters' => ['event' => $event?->id, 'barangay' => $barangay?->id, 'only_unclaimed' => $onlyUnclaimed],
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
            'list' => $event === null || $barangay === null ? null : $builder->handle($event, $barangay, $onlyUnclaimed),
            'generated' => ['at' => now()->format('M j, Y g:i A'), 'by' => $request->user()->name],
        ]);
    }

    public function distributionListCsv(Request $request, BuildDistributionList $builder, CsvExport $csv): StreamedResponse
    {
        ['event' => $event, 'barangay' => $barangay, 'onlyUnclaimed' => $onlyUnclaimed] = $this->listContext($request);

        abort_if($event === null || $barangay === null, 404);

        $list = $builder->handle($event, $barangay, $onlyUnclaimed);

        return $csv->download($this->filename('distribution-list-'.Str::slug($barangay->name), $event), BuildDistributionList::HEADERS, $builder->flatten($list['households']));
    }

    /**
     * The events, barangays and filters of the printable list. Only events that are not drafts, and barangays of
     * the event's municipality that the viewer may see.
     *
     * @return array{events: Collection<int, ReliefEvent>, event: ReliefEvent|null, barangays: Collection<int, Barangay>, barangay: Barangay|null, onlyUnclaimed: bool}
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
        abort_unless(ReliefEvent::query()->visibleTo($request->user())->whereKey($event->id)->exists(), 403);
    }

    private function filename(string $kind, ReliefEvent $event): string
    {
        return Str::slug($event->title).'-'.$kind.'-'.now()->format('Y-m-d').'.csv';
    }
}
