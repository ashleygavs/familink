<?php

namespace App\Http\Controllers;

use App\Enums\ResidentStatus;
use App\Models\Resident;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ResidentListController extends Controller
{
    /**
     * Residents of the barangays the user works in, searchable by name, ID or household code, so a QR card
     * can be found and printed again at any time.
     */
    public function __invoke(Request $request): Response
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:Approved,Pending,all'],
        ]);

        $search = trim($input['q'] ?? '');
        $status = $input['status'] ?? 'Approved';

        $residents = Resident::query()
            ->with(['household', 'barangay'])
            ->visibleTo($request->user())
            ->whereNot('status', ResidentStatus::Archived->value)
            ->whereNot('status', ResidentStatus::Listed->value)
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
                'is_deceased' => $resident->is_deceased,
            ]);

        return Inertia::render('residents/index', [
            'residents' => $residents,
            'filters' => ['q' => $search, 'status' => $status],
        ]);
    }
}
