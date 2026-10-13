<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveBarangayRequest;
use App\Models\Barangay;
use Illuminate\Http\RedirectResponse;

class BarangayController extends Controller
{
    public function store(SaveBarangayRequest $request): RedirectResponse
    {
        $barangay = Barangay::query()->create($request->validated());

        return back()->with('success', "Barangay {$barangay->name} was added.");
    }

    public function update(SaveBarangayRequest $request, Barangay $barangay): RedirectResponse
    {
        $barangay->update($request->validated());

        return back()->with('success', "Barangay {$barangay->name} was updated.");
    }

    /**
     * A barangay with residents, households or users is kept so no records are orphaned.
     */
    public function destroy(Barangay $barangay): RedirectResponse
    {
        if ($barangay->residents()->exists() || $barangay->households()->exists() || $barangay->admins()->exists() || $barangay->users()->exists()) {
            return back()->withErrors(['barangay' => "Barangay {$barangay->name} still has residents, households or staff, so it cannot be deleted."]);
        }

        $barangay->delete();

        return back()->with('success', "Barangay {$barangay->name} was deleted.");
    }
}
