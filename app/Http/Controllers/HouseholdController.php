<?php

namespace App\Http\Controllers;

use App\Http\Resources\HouseholdOptionResource;
use App\Models\Household;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HouseholdController extends Controller
{
    /**
     * Search the households of one barangay by code, address or head's name.
     */
    public function search(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'barangay_id' => ['required', 'integer'],
        ]);

        $like = '%'.addcslashes($filters['q'], '%_\\').'%';

        $households = Household::query()
            ->with('head')
            ->withCount('residents')
            ->where('barangay_id', $request->user()->barangay_id ?? $filters['barangay_id'])
            ->where(fn ($match) => $match
                ->where('household_code', 'like', $like)
                ->orWhere('street_address', 'like', $like)
                ->orWhereHas('head', fn ($head) => $head
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)))
            ->orderBy('household_code')
            ->limit(10)
            ->get();

        return HouseholdOptionResource::collection($households);
    }
}
