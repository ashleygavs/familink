<?php

namespace App\Http\Controllers;

use App\Enums\ResidentStatus;
use App\Enums\RoleName;
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
     * Encoders only see the figures of their own barangay.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $barangayId = $user->hasRole(RoleName::User) ? $user->barangay_id : null;

        $residents = Resident::query()->when($barangayId, fn ($query) => $query->where('barangay_id', $barangayId));

        return Inertia::render('dashboard', [
            'stats' => [
                'approvedResidents' => (clone $residents)->where('status', ResidentStatus::Approved)->count(),
                'pendingResidents' => (clone $residents)->where('status', ResidentStatus::Pending)->count(),
                'households' => Household::query()->when($barangayId, fn ($query) => $query->where('barangay_id', $barangayId))->count(),
                'reliefClaims' => ReliefLog::query()
                    ->when($barangayId, fn ($query) => $query->whereHas('resident', fn ($resident) => $resident->where('barangay_id', $barangayId)))
                    ->count(),
            ],
        ]);
    }
}
