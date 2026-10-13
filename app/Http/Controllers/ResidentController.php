<?php

namespace App\Http\Controllers;

use App\Actions\Residents\RegisterResident;
use App\Enums\CivilStatus;
use App\Enums\Gender;
use App\Enums\HouseholdRole;
use App\Enums\ResidentStatus;
use App\Http\Requests\StoreResidentRequest;
use App\Http\Resources\ResidentOptionResource;
use App\Models\Barangay;
use App\Models\Resident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ResidentController extends Controller
{
    /**
     * Show the resident registration form.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('encoder/register-resident', [
            'barangays' => Barangay::query()
                ->when($user->barangay_id, fn ($query, $barangayId) => $query->whereKey($barangayId))
                ->orderBy('name')
                ->get(['id', 'name']),
            'lockedBarangayId' => $user->barangay_id,
            'options' => [
                'genders' => array_column(Gender::cases(), 'value'),
                'civilStatuses' => array_column(CivilStatus::cases(), 'value'),
                'householdRoles' => array_values(array_map(
                    fn (HouseholdRole $role) => $role->value,
                    array_filter(HouseholdRole::cases(), fn (HouseholdRole $role) => $role !== HouseholdRole::Head),
                )),
            ],
            'registered' => $request->session()->get('registered'),
        ]);
    }

    /**
     * Register a new resident (Pending until an admin approves).
     */
    public function store(StoreResidentRequest $request, RegisterResident $registerResident): RedirectResponse
    {
        $resident = $registerResident->handle($request->user(), $request->validated())->load('household');

        return to_route('encoder.register')->with('registered', [
            'id' => $resident->id,
            'full_name' => $resident->full_name,
            'system_id_no' => $resident->system_id_no,
            'household_code' => $resident->household->household_code,
            'qr_code_hash' => $resident->qr_code_hash,
        ]);
    }

    /**
     * Search residents by name or system ID across all barangays.
     */
    public function search(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'older_than' => ['nullable', 'date'],
            'born_after' => ['nullable', 'date'],
            'unlinked_parent' => ['nullable', Rule::in(['father', 'mother'])],
            'barangay_id' => ['nullable', 'integer'],
            'without_spouse' => ['nullable', 'boolean'],
        ]);

        $query = Resident::query()
            ->with(['barangay', 'household.head'])
            ->whereNot('status', ResidentStatus::Archived->value)
            ->when($filters['gender'] ?? null, fn ($query, $gender) => $query->where('gender', $gender))
            ->when($filters['older_than'] ?? null, fn ($query, $date) => $query->whereDate('birthdate', '<', $date))
            ->when($filters['born_after'] ?? null, fn ($query, $date) => $query->whereDate('birthdate', '>', $date))
            ->when($filters['unlinked_parent'] ?? null, fn ($query, $parent) => $query->whereNull("{$parent}_id"))
            ->when($filters['barangay_id'] ?? null, fn ($query, $barangayId) => $query->where('barangay_id', $barangayId))
            ->when($request->boolean('without_spouse'), fn ($query) => $query->whereNull('spouse_id'));

        foreach (preg_split('/\s+/', trim($filters['q']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            $like = '%'.addcslashes($term, '%_\\').'%';

            $query->where(fn ($match) => $match
                ->where('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('suffix', 'like', $like)
                ->orWhere('system_id_no', 'like', $like));
        }

        return ResidentOptionResource::collection(
            $query->orderBy('last_name')->orderBy('first_name')->limit(10)->get(),
        );
    }
}
