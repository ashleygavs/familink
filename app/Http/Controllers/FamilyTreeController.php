<?php

namespace App\Http\Controllers;

use App\Actions\Relief\BuildFamilyTree;
use App\Models\Household;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FamilyTreeController extends Controller
{
    /**
     * The family tree around one household. Only people who may work with the household's barangay can open it.
     */
    public function __invoke(Request $request, Household $household, BuildFamilyTree $buildFamilyTree): Response
    {
        abort_unless($request->user()->canAccessBarangay($household->barangay_id), 403);

        $household->load(['barangay', 'head']);

        return Inertia::render('reports/family-tree', [
            'household' => [
                'id' => $household->id,
                'household_code' => $household->household_code,
                'street_address' => $household->street_address,
                'barangay_name' => $household->barangay->name,
                'head_name' => $household->head?->full_name,
            ],
            'tree' => $buildFamilyTree->handle($household),
        ]);
    }
}
