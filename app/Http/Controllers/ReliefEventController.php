<?php

namespace App\Http\Controllers;

use App\Enums\AllocationType;
use App\Enums\EventStatus;
use App\Enums\RoleName;
use App\Http\Requests\SaveReliefEventRequest;
use App\Models\Municipality;
use App\Models\ReliefEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReliefEventController extends Controller
{
    /**
     * Relief events of the municipalities the user works in, newest first.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $events = ReliefEvent::query()
            ->visibleTo($user)
            ->with('municipality')
            ->withCount('logs')
            ->latest('start_date')
            ->latest('id')
            ->get()
            ->map(fn (ReliefEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'municipality_id' => $event->municipality_id,
                'municipality_name' => $event->municipality?->name,
                'allocation_type' => $event->allocation_type->value,
                'max_claims_per_household' => $event->max_claims_per_household,
                'start_date' => $event->start_date->toDateString(),
                'end_date' => $event->end_date->toDateString(),
                'status' => $event->status->value,
                'next_statuses' => array_map(fn (EventStatus $status): string => $status->value, $event->status->next()),
                'is_open' => $event->isOpenForScanning(),
                'claims_count' => $event->logs_count,
                'can_edit' => $event->status !== EventStatus::Closed,
                'can_delete' => $event->status === EventStatus::Draft && $event->logs_count === 0,
            ]);

        $ids = $user->accessibleMunicipalityIds();

        return Inertia::render('admin/events', [
            'events' => $events,
            'municipalities' => Municipality::query()
                ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
                ->orderBy('name')
                ->get(['id', 'name']),
            'allocationTypes' => array_column(AllocationType::cases(), 'value'),
            'canSetLimit' => $user->hasRole(RoleName::SuperAdmin),
        ]);
    }

    public function store(SaveReliefEventRequest $request): RedirectResponse
    {
        $event = ReliefEvent::query()->create([...$request->savedData(), 'created_by_user_id' => $request->user()->id]);

        return back()->with('success', "{$event->title} was created.");
    }

    public function update(SaveReliefEventRequest $request, ReliefEvent $event): RedirectResponse
    {
        $event->update($request->savedData());

        return back()->with('success', "{$event->title} was updated.");
    }

    /**
     * Only a draft that nobody has claimed from can be deleted. Anything else is closed, never erased.
     */
    public function destroy(Request $request, ReliefEvent $event): RedirectResponse
    {
        abort_unless(ReliefEvent::query()->visibleTo($request->user())->whereKey($event->id)->exists(), 403);

        if ($event->status !== EventStatus::Draft || $event->logs()->exists()) {
            return back()->withErrors(['event' => 'Only a draft with no claims can be deleted. Close the event instead.']);
        }

        $event->delete();

        return back()->with('success', "{$event->title} was deleted.");
    }
}
