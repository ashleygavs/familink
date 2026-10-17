<?php

namespace App\Http\Controllers;

use App\Actions\Relief\ScanRelief;
use App\Enums\EventStatus;
use App\Enums\RepresentativeRelationship;
use App\Http\Requests\ScanReliefRequest;
use App\Models\ReliefEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReliefController extends Controller
{
    /**
     * The scanner screen with the events that can be scanned right now: Active, inside their dates and
     * in the encoder's municipality.
     */
    public function scanner(Request $request): Response
    {
        $events = ReliefEvent::query()
            ->visibleTo($request->user())
            ->with('municipality')
            ->where('status', EventStatus::Active->value)
            ->orderBy('end_date')
            ->get()
            ->filter(fn (ReliefEvent $event): bool => $event->isOpenForScanning())
            ->map(fn (ReliefEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'municipality_name' => $event->municipality?->name,
                'allocation_type' => $event->allocation_type->value,
                'max_claims_per_household' => $event->claimLimitPerHousehold(),
                'end_date' => $event->end_date->toDateString(),
            ])
            ->values();

        return Inertia::render('encoder/scanner', [
            'events' => $events,
            'relationships' => RepresentativeRelationship::values(),
        ]);
    }

    /**
     * Check a scanned QR code (or a typed resident ID) against an event and record the claim.
     * Always answers 200 with a granted or denied result and the reason; only a bad request is an error.
     */
    public function scan(ScanReliefRequest $request, ScanRelief $scanRelief): JsonResponse
    {
        $event = ReliefEvent::query()->findOrFail($request->integer('event_id'));

        return response()->json($scanRelief->handle($request->user(), $event, $request->string('code')->toString(), $request->representative()));
    }
}
