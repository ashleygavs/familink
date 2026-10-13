<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveMunicipalityRequest;
use App\Models\Barangay;
use App\Models\Municipality;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MunicipalityController extends Controller
{
    /**
     * Municipalities with their barangays. Only the super admin manages them.
     */
    public function index(): Response
    {
        return Inertia::render('admin/municipalities', [
            'municipalities' => Municipality::query()
                ->with(['barangays' => fn ($query) => $query->withCount(['residents', 'households'])->orderBy('name')])
                ->orderBy('name')
                ->get()
                ->map(fn (Municipality $municipality): array => [
                    'id' => $municipality->id,
                    'name' => $municipality->name,
                    'province' => $municipality->province,
                    'barangays' => $municipality->barangays->map(fn (Barangay $barangay): array => [
                        'id' => $barangay->id,
                        'name' => $barangay->name,
                        'code' => $barangay->code,
                        'residents_count' => $barangay->residents_count,
                        'households_count' => $barangay->households_count,
                    ])->all(),
                ]),
        ]);
    }

    public function store(SaveMunicipalityRequest $request): RedirectResponse
    {
        $municipality = Municipality::query()->create($request->validated());

        return back()->with('success', "{$municipality->name} was added.");
    }

    public function update(SaveMunicipalityRequest $request, Municipality $municipality): RedirectResponse
    {
        $municipality->update($request->validated());

        return back()->with('success', "{$municipality->name} was updated.");
    }

    public function destroy(Municipality $municipality): RedirectResponse
    {
        if ($municipality->barangays()->exists()) {
            return back()->withErrors(['municipality' => 'Remove or move its barangays first.']);
        }

        $municipality->delete();

        return back()->with('success', "{$municipality->name} was deleted.");
    }
}
