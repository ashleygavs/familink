<?php

namespace App\Http\Controllers;

use App\Actions\Relief\FindSplitHouseholds;
use App\Enums\EventStatus;
use App\Enums\RoleName;
use App\Http\Requests\StoreHouseholdReviewRequest;
use App\Models\Household;
use App\Models\HouseholdReview;
use App\Models\ReliefEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HouseholdReviewController extends Controller
{
    /**
     * Pairs of households that may be one family split in two, so one family does not claim twice. When an event is
     * chosen, each pair also shows whether both households claimed in it.
     */
    public function index(Request $request, FindSplitHouseholds $find): Response
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
            'reviewed' => ['nullable', 'boolean'],
        ]);

        $event = isset($input['event'])
            ? $events->firstWhere('id', (int) $input['event'])
            : ($events->first(fn (ReliefEvent $candidate) => $candidate->status === EventStatus::Active) ?? $events->first());

        $showReviewed = (bool) ($input['reviewed'] ?? false);
        $cases = collect($find->handle($user->accessibleBarangayIds(), $event));
        $open = $cases->filter(fn (array $case): bool => $case['review'] === null);

        return Inertia::render('reports/household-review', [
            'filters' => ['event' => $event?->id, 'reviewed' => $showReviewed],
            'events' => $events->map(fn (ReliefEvent $candidate): array => [
                'id' => $candidate->id,
                'title' => $candidate->title,
                'municipality_name' => $candidate->municipality?->name,
                'status' => $candidate->status->value,
            ])->values(),
            'counts' => ['open' => $open->count(), 'reviewed' => $cases->count() - $open->count()],
            'cases' => ($showReviewed ? $cases->filter(fn (array $case): bool => $case['review'] !== null) : $open)->values(),
            'canReview' => ! $user->hasRole(RoleName::Stakeholder),
        ]);
    }

    /**
     * Record that an admin looked at the two households and they are fine as they are.
     */
    public function store(StoreHouseholdReviewRequest $request): RedirectResponse
    {
        $pair = HouseholdReview::pair((int) $request->integer('first_household_id'), (int) $request->integer('second_household_id'));

        HouseholdReview::query()->updateOrCreate($pair, [
            'reason' => trim($request->string('reason')->toString()),
            'reviewed_by_user_id' => $request->user()->id,
        ]);

        $codes = Household::query()->whereIn('id', $pair)->orderBy('household_code')->pluck('household_code');

        return back()->with('success', "{$codes->implode(' and ')} were marked as reviewed.");
    }

    /**
     * Put a reviewed pair back on the list, for example when the family's situation has changed.
     */
    public function destroy(Request $request, HouseholdReview $review): RedirectResponse
    {
        $barangays = Household::query()->whereIn('id', [$review->first_household_id, $review->second_household_id])->pluck('barangay_id');

        abort_unless($barangays->contains(fn ($id): bool => $request->user()->canAccessBarangay((int) $id)), 403);

        $review->delete();

        return back()->with('success', 'The pair is back on the list.');
    }
}
