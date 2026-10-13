<?php

namespace App\Http\Controllers;

use App\Actions\Residents\FindFamilyMatches;
use App\Enums\CivilStatus;
use App\Enums\Gender;
use App\Http\Resources\FamilySuggestionsResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FamilySuggestionController extends Controller
{
    /**
     * Suggest likely relatives, and listed records the person may claim, from what is already on record.
     * Encoders only see matches from their own barangay.
     */
    public function __invoke(Request $request, FindFamilyMatches $findFamilyMatches): FamilySuggestionsResource
    {
        $input = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'maiden_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birthdate' => ['required', 'date', 'before_or_equal:today', 'after:1899-12-31'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'civil_status' => ['nullable', Rule::enum(CivilStatus::class)],
            'household_id' => ['nullable', 'integer'],
            'barangay_id' => ['nullable', 'integer'],
        ]);

        $ownBarangayId = $request->user()->barangay_id;

        $person = [
            'first_name' => $input['first_name'],
            'middle_name' => $input['middle_name'] ?? null,
            'maiden_name' => $input['maiden_name'] ?? null,
            'last_name' => $input['last_name'],
            'birthdate' => $input['birthdate'],
            'gender' => $input['gender'] ?? null,
            'civil_status' => $input['civil_status'] ?? null,
            'household_id' => isset($input['household_id']) ? (int) $input['household_id'] : null,
            'barangay_id' => $ownBarangayId ?? (isset($input['barangay_id']) ? (int) $input['barangay_id'] : null),
        ];

        return FamilySuggestionsResource::make($findFamilyMatches->handle($person, $ownBarangayId));
    }
}
