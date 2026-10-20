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
     * The reach says how far out it goes: close family, plus first cousins, or plus second cousins.
     */
    public function __invoke(Request $request, Household $household, BuildFamilyTree $buildFamilyTree): Response
    {
        abort_unless($request->user()->canAccessBarangay($household->barangay_id), 403);

        // An unknown reach, from an old or mistyped link, just shows the close family.
        $reach = $request->query('reach');
        $reach = is_string($reach) && array_key_exists($reach, BuildFamilyTree::REACHES) ? $reach : 'close';

        $household->load(['barangay', 'head']);

        return Inertia::render('reports/family-tree', [
            'household' => [
                'id' => $household->id,
                'household_code' => $household->household_code,
                'street_address' => $household->street_address,
                'barangay_name' => $household->barangay->name,
                'head_name' => $household->head?->full_name,
            ],
            'tree' => $buildFamilyTree->handle($household, $reach),
        ]);
    }
}
