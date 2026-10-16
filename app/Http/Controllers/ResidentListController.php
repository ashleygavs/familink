<?php

namespace App\Http\Controllers;

use App\Enums\ResidentStatus;
use App\Models\Municipality;
use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ResidentListController extends Controller
{
    /**
     * Residents of the barangays the user works in, searchable by name, ID or household code, so a QR card
     * can be found and printed again at any time. Anyone who works in more than one barangay, such as a super
     * admin, can narrow the list to one municipality or one barangay.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $places = $this->places($user->accessibleBarangayIds());
        $allowedBarangayIds = collect($places)->flatMap(fn (array $place): array => array_column($place['barangays'], 'id'))->all();

        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:Approved,Pending,all'],
            'municipality' => ['nullable', 'integer', Rule::in(array_column($places, 'id'))],
            'barangay' => ['nullable', 'integer', Rule::in($allowedBarangayIds)],
        ]);

        $search = trim($input['q'] ?? '');
        $status = $input['status'] ?? 'Approved';
        $barangayId = isset($input['barangay']) ? (int) $input['barangay'] : null;
        $municipalityId = isset($input['municipality']) ? (int) $input['municipality'] : null;

        // A chosen barangay wins. Otherwise a chosen municipality means all of its barangays the user may see.
        $placeIds = match (true) {
            $barangayId !== null => [$barangayId],
            $municipalityId !== null => array_column(collect($places)->firstWhere('id', $municipalityId)['barangays'] ?? [], 'id'),
            default => null,
        };

        $residents = Resident::query()
            ->with(['household', 'barangay.municipality'])
            ->visibleTo($user)
            ->whereNot('status', ResidentStatus::Archived->value)
            ->whereNot('status', ResidentStatus::Listed->value)
            ->when($placeIds !== null, fn ($query) => $query->whereIn('barangay_id', $placeIds))
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

                $query->where(fn ($inner) => $inner
                    ->whereRaw("concat_ws(' ', first_name, middle_name, last_name, suffix) like ?", [$like])
                    ->orWhere('system_id_no', 'like', $like)
                    ->orWhereHas('household', fn ($household) => $household->where('household_code', 'like', $like)));
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Resident $resident): array => [
                'id' => $resident->id,
                'full_name' => $resident->full_name,
                'system_id_no' => $resident->system_id_no,
                'status' => $resident->status->value,
                'household_id' => $resident->household_id,
                'household_code' => $resident->household?->household_code,
                'barangay_name' => $resident->barangay?->name,
                'municipality_name' => $resident->barangay?->municipality?->name,
                'is_deceased' => $resident->is_deceased,
            ]);

        return Inertia::render('residents/index', [
            'residents' => $residents,
            'filters' => ['q' => $search, 'status' => $status, 'municipality' => $municipalityId, 'barangay' => $barangayId],
            'places' => $places,
        ]);
    }

    /**
     * Municipalities with the barangays the user may filter by. Null means every barangay.
     *
     * @param  list<int>|null  $scope
     * @return list<array{id: int, name: string, barangays: list<array{id: int, name: string}>}>
     */
    private function places(?array $scope): array
    {
        return array_values(Municipality::query()
            ->with(['barangays' => fn ($query) => $query->when($scope !== null, fn ($inner) => $inner->whereIn('id', $scope))->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->filter(fn (Municipality $municipality): bool => $municipality->barangays->isNotEmpty())
            ->map(fn (Municipality $municipality): array => [
                'id' => $municipality->id,
                'name' => $municipality->name,
                'barangays' => array_values($municipality->barangays->map(fn ($barangay): array => ['id' => $barangay->id, 'name' => $barangay->name])->all()),
            ])
            ->all());
    }
}
