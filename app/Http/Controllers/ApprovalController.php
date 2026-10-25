<?php

namespace App\Http\Controllers;

use App\Actions\Residents\ApproveRegistration;
use App\Actions\Residents\ArchiveRegistration;
use App\Actions\Residents\FindFamilyMatches;
use App\Actions\Residents\LinkRelatives;
use App\Actions\Residents\RecordSuggestionFeedback;
use App\Actions\Residents\UnlinkRelative;
use App\Enums\RelativeKind;
use App\Enums\ResidentStatus;
use App\Http\Requests\ArchiveRegistrationRequest;
use App\Http\Requests\LinkRelativesRequest;
use App\Http\Requests\SuggestionFeedbackRequest;
use App\Http\Requests\UnlinkRelativeRequest;
use App\Http\Resources\FamilySuggestionsResource;
use App\Models\RelativeLink;
use App\Models\Resident;
use App\Models\ResidentChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalController extends Controller
{
    /**
     * List residents waiting for approval, oldest first, with suggested family links.
     */
    public function index(Request $request, FindFamilyMatches $findFamilyMatches, RecordSuggestionFeedback $feedback): Response
    {
        $page = Resident::query()
            ->visibleTo($request->user())
            ->with(['barangay', 'household', 'father', 'mother', 'spouse', 'childrenAsFather', 'childrenAsMother'])
            ->where('status', ResidentStatus::Pending->value)
            ->oldest()
            ->paginate(10);

        // Who entered each registration, so the person approving it can see where it came from.
        $registeredBy = ResidentChange::query()
            ->with('changedBy')
            ->where('type', ResidentChange::REGISTERED)
            ->whereIn('resident_id', $page->pluck('id'))
            ->get()
            ->keyBy('resident_id');

        $pending = $page
            ->through(fn (Resident $resident) => [
                'id' => $resident->id,
                'full_name' => $resident->full_name,
                'system_id_no' => $resident->system_id_no,
                'birthdate' => $resident->birthdate->toDateString(),
                'gender' => $resident->gender,
                'civil_status' => $resident->civil_status,
                'household_role' => $resident->household_role,
                'barangay_name' => $resident->barangay?->name,
                'household_id' => $resident->household_id,
                'household_code' => $resident->household?->household_code,
                'father_name' => $resident->father?->full_name,
                'mother_name' => $resident->mother?->full_name,
                'spouse_name' => $resident->spouse?->full_name,
                'children' => $resident->childrenAsFather->merge($resident->childrenAsMother)->unique('id')
                    ->map(fn (Resident $child): array => ['id' => $child->id, 'full_name' => $child->full_name])
                    ->values()->all(),
                'registered_at' => $resident->created_at?->toIso8601String(),
                'registered_by' => $registeredBy->get($resident->id)?->changedBy->name,
                'declared_relatives' => $this->declaredRelatives($resident),
                'suggestions' => $this->suggestionsFor($findFamilyMatches, $feedback, $resident, $request->user()->accessibleBarangayIds()),
            ]);

        return Inertia::render('admin/approvals', ['pending' => $pending]);
    }

    public function approve(Request $request, Resident $resident, ApproveRegistration $approve): RedirectResponse
    {
        abort_unless($this->withinScope($request, $resident), 403);
        abort_unless($resident->status === ResidentStatus::Pending, 409, 'This resident is no longer pending.');

        $approve->handle($request->user(), $resident);

        return back()->with('success', "{$resident->full_name} was approved.");
    }

    /**
     * Turn a registration down. A reason is required and kept in the person's history.
     */
    public function archive(ArchiveRegistrationRequest $request, Resident $resident, ArchiveRegistration $archive): RedirectResponse
    {
        abort_unless($resident->status === ResidentStatus::Pending, 409, 'This resident is no longer pending.');

        $archive->handle($request->user(), $resident, $request->validated('reason'));

        return back()->with('success', "{$resident->full_name} was archived.");
    }

    /**
     * Confirm suggested family links for a pending resident.
     */
    public function links(LinkRelativesRequest $request, Resident $resident, LinkRelatives $linkRelatives, RecordSuggestionFeedback $feedback): RedirectResponse
    {
        abort_unless($this->withinScope($request, $resident), 403);
        abort_unless($resident->status === ResidentStatus::Pending, 409, 'This resident is no longer pending.');

        $linkRelatives->handle($resident, $request->validated(), $request->user());
        $feedback->handle($resident, $request->user(), (array) $request->validated('suggestion_feedback', []), 'approval');

        return back()->with('success', "Family links saved for {$resident->full_name}.");
    }

    /**
     * Cut a family link that was made by mistake (a parent, spouse, child or named relative).
     */
    public function unlink(UnlinkRelativeRequest $request, Resident $resident, UnlinkRelative $unlinkRelative): RedirectResponse
    {
        abort_unless($this->withinScope($request, $resident), 403);
        abort_unless($resident->status === ResidentStatus::Pending, 409, 'This resident is no longer pending.');

        $unlinkRelative->handle($resident, $request->validated('kind'), $request->validated('id'), $request->user());

        return back()->with('success', "Family link removed for {$resident->full_name}.");
    }

    /**
     * Remember the admin's answer to a suggested relative (for example a rejected match, which is then
     * no longer suggested for this resident).
     */
    public function feedback(SuggestionFeedbackRequest $request, Resident $resident, RecordSuggestionFeedback $feedback): RedirectResponse
    {
        abort_unless($this->withinScope($request, $resident), 403);
        abort_unless($resident->status === ResidentStatus::Pending, 409, 'This resident is no longer pending.');

        $feedback->handle($resident, $request->user(), (array) $request->validated('suggestion_feedback'), 'approval');

        return back();
    }

    /**
     * The relatives the resident named when registering, plus those who named the resident.
     *
     * @return array<int, array{id: int, relation: string, name: string, system_id_no: string}>
     */
    private function declaredRelatives(Resident $resident): array
    {
        return RelativeLink::query()
            ->with(['resident', 'relative'])
            ->where('resident_id', $resident->id)
            ->orWhere('relative_id', $resident->id)
            ->get()
            ->map(function (RelativeLink $link) use ($resident): array {
                $named = $link->resident_id === $resident->id;
                $other = $named ? $link->relative : $link->resident;

                return [
                    'id' => $link->id,
                    'relation' => ($named ? $link->relation : $link->relation->inverse())->label(),
                    'name' => $other->full_name,
                    'system_id_no' => $other->system_id_no,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Suggestions for the family slots that are still empty. Only people in the barangays the admin handles
     * are suggested, so an admin never sees residents outside their area.
     *
     * @param  list<int>|null  $scope  The admin's barangays; null for a super admin, who sees everyone.
     * @return array<string, mixed>
     */
    private function suggestionsFor(FindFamilyMatches $findFamilyMatches, RecordSuggestionFeedback $feedback, Resident $resident, ?array $scope): array
    {
        $answered = $feedback->rejectedFor($resident);

        $matches = $findFamilyMatches->handle([
            'first_name' => $resident->first_name,
            'middle_name' => $resident->middle_name,
            'maiden_name' => $resident->maiden_name,
            'last_name' => $resident->last_name,
            'birthdate' => $resident->birthdate->toDateString(),
            'gender' => $resident->gender,
            'civil_status' => $resident->civil_status,
            'household_id' => $resident->household_id,
            'barangay_id' => $resident->barangay_id,
        ], $scope, $resident, [
            'rejected' => [
                'father' => $answered['father'] ?? [],
                'mother' => $answered['mother'] ?? [],
                'spouse' => $answered['spouse'] ?? [],
                'children' => $answered['children'] ?? [],
                'relatives' => array_intersect_key($answered, array_flip(RelativeKind::values())),
            ],
        ]);

        $matches['claims'] = [];
        $matches['relatives'] = [];

        foreach (['father' => 'father_id', 'mother' => 'mother_id', 'spouse' => 'spouse_id'] as $slot => $column) {
            if ($resident->{$column} !== null) {
                $matches[$slot] = [];
            }
        }

        return FamilySuggestionsResource::make($matches)->resolve();
    }

    /**
     * Admins only act on residents of the barangays they handle.
     */
    private function withinScope(Request $request, Resident $resident): bool
    {
        return Resident::query()->visibleTo($request->user())->whereKey($resident->id)->exists();
    }
}
