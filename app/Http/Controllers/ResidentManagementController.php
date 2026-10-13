<?php

namespace App\Http\Controllers;

use App\Actions\Residents\MoveHousehold;
use App\Actions\Residents\RecordMarriage;
use App\Enums\HouseholdRole;
use App\Enums\ResidentStatus;
use App\Http\Requests\MoveHouseholdRequest;
use App\Http\Requests\RecordMarriageRequest;
use App\Http\Resources\HouseholdOptionResource;
use App\Http\Resources\ResidentOptionResource;
use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;

class ResidentManagementController extends Controller
{
    /**
     * A resident's family and household, with the changes an admin can make after approval and a history of
     * the ones already made.
     */
    public function show(Request $request, Resident $resident, MoveHousehold $moveHousehold): Response
    {
        $this->authorizeResident($request, $resident);

        $resident->load(['barangay', 'household.head', 'father', 'mother', 'spouse', 'childrenAsFather', 'childrenAsMother']);
        $household = $resident->household;

        return Inertia::render('admin/resident', [
            'resident' => [
                'id' => $resident->id,
                'full_name' => $resident->full_name,
                'system_id_no' => $resident->system_id_no,
                'status' => $resident->status->value,
                'civil_status' => $resident->civil_status,
                'gender' => $resident->gender,
                'birthdate' => $resident->birthdate->toDateString(),
                'barangay_name' => $resident->barangay?->name,
                'is_deceased' => $resident->is_deceased,
                'is_head' => $household?->head_resident_id === $resident->id,
            ],
            'family' => [
                'father' => $resident->father?->full_name,
                'mother' => $resident->mother?->full_name,
                'spouse' => $resident->spouse === null ? null : ['id' => $resident->spouse->id, 'full_name' => $resident->spouse->full_name],
                'children' => $resident->childrenAsFather->merge($resident->childrenAsMother)->unique('id')
                    ->map(fn (Resident $child): array => ['id' => $child->id, 'full_name' => $child->full_name])->values()->all(),
            ],
            'household' => $household === null ? null : [
                'id' => $household->id,
                'household_code' => $household->household_code,
                'street_address' => $household->street_address,
                'role' => $resident->household_role,
                'head_name' => $household->head?->full_name,
                'members' => Resident::query()
                    ->where('household_id', $household->id)
                    ->whereKeyNot($resident->id)
                    ->whereNot('status', ResidentStatus::Archived->value)
                    ->orderBy('last_name')->orderBy('first_name')
                    ->get()
                    ->map(fn (Resident $member): array => ['id' => $member->id, 'full_name' => $member->full_name, 'role' => $member->household_role])
                    ->all(),
            ],
            'openEvents' => $moveHousehold->blockingEvents($resident),
            'canMarry' => $resident->status === ResidentStatus::Approved && ! $resident->is_deceased
                && $resident->spouse_id === null && $resident->birthdate->age >= 16,
            'roleChoices' => array_values(array_map(
                fn (HouseholdRole $role): string => $role->value,
                array_filter(HouseholdRole::cases(), fn (HouseholdRole $role): bool => $role !== HouseholdRole::Head),
            )),
            'history' => $resident->changes()->with('changedBy')->latest()->limit(30)->get()
                ->map(fn (ResidentChange $change): array => [
                    'id' => $change->id,
                    'type' => $change->type,
                    'summary' => $change->summary,
                    'changed_by' => $change->changedBy->name,
                    'changed_at' => $change->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    /**
     * People who could be recorded as this resident's spouse: approved, living, unmarried adults in the admin's
     * barangays who are not close relatives.
     */
    public function spouseCandidates(Request $request, Resident $resident, RecordMarriage $marriage): AnonymousResourceCollection
    {
        $this->authorizeResident($request, $resident);

        $term = trim($request->string('q')->toString());
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $candidates = Resident::query()
            ->with(['barangay', 'household'])
            ->visibleTo($request->user())
            ->whereKeyNot($resident->id)
            ->where('status', ResidentStatus::Approved->value)
            ->where('is_deceased', false)
            ->whereNull('spouse_id')
            ->where('birthdate', '<=', now()->subYears(16)->toDateString())
            ->when($term !== '', fn ($query) => $query->where(fn ($match) => $match
                ->whereRaw("concat_ws(' ', first_name, middle_name, last_name, suffix) like ?", [$like])
                ->orWhere('system_id_no', 'like', $like)))
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(40)
            ->get()
            ->reject(fn (Resident $candidate): bool => $marriage->areCloseRelatives($resident, $candidate))
            ->take(10)
            ->values();

        return ResidentOptionResource::collection($candidates);
    }

    /**
     * Households a resident can be moved to, in the barangays the admin handles.
     */
    public function households(Request $request): AnonymousResourceCollection
    {
        $term = trim($request->string('q')->toString());
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $scope = $request->user()->accessibleBarangayIds();

        $households = Household::query()
            ->with('head')
            ->withCount('residents')
            ->when($scope !== null, fn ($query) => $query->whereIn('barangay_id', $scope))
            ->where(fn ($match) => $match
                ->where('household_code', 'like', $like)
                ->orWhere('street_address', 'like', $like)
                ->orWhereHas('head', fn ($head) => $head->whereRaw("concat_ws(' ', first_name, middle_name, last_name, suffix) like ?", [$like])))
            ->orderBy('household_code')
            ->limit(10)
            ->get();

        return HouseholdOptionResource::collection($households);
    }

    public function marriage(RecordMarriageRequest $request, Resident $resident, RecordMarriage $recordMarriage): RedirectResponse
    {
        $spouse = Resident::query()->findOrFail($request->integer('spouse_id'));

        $recordMarriage->handle(
            $request->user(),
            $resident,
            $spouse,
            $request->filled('takes_surname_id') ? $request->integer('takes_surname_id') : null,
        );

        return back()->with('success', "{$resident->full_name} and {$spouse->full_name} are now recorded as married.");
    }

    public function household(MoveHouseholdRequest $request, Resident $resident, MoveHousehold $moveHousehold): RedirectResponse
    {
        $household = $moveHousehold->handle($request->user(), $resident, $request->validated());

        return back()->with('success', "{$resident->full_name} now lives in {$household->household_code}. Relief already claimed stays with the old household.");
    }

    private function authorizeResident(Request $request, Resident $resident): void
    {
        abort_unless(Resident::query()->visibleTo($request->user())->whereKey($resident->id)->exists(), 403);
    }
}
