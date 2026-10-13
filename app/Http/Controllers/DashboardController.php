<?php

namespace App\Http\Controllers;

use App\Enums\ResidentStatus;
use App\Models\Household;
use App\Models\ReliefLog;
use App\Models\Resident;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the dashboard with summary counts.
     *
     * Everyone only sees the figures of the barangays they may work with.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $barangayIds = $user->accessibleBarangayIds();

        $residents = Resident::query()->visibleTo($user);

        return Inertia::render('dashboard', [
            'stats' => [
                'approvedResidents' => (clone $residents)->where('status', ResidentStatus::Approved)->count(),
                'pendingResidents' => (clone $residents)->where('status', ResidentStatus::Pending)->count(),
                'households' => Household::query()->when($barangayIds !== null, fn ($query) => $query->whereIn('barangay_id', $barangayIds))->count(),
                'reliefClaims' => ReliefLog::query()
                    ->whereHas('resident', fn ($resident) => $resident->visibleTo($user))
                    ->count(),
            ],
        ]);
    }
}
