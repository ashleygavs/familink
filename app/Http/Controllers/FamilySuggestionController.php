<?php

namespace App\Http\Controllers;

use App\Actions\Residents\FindFamilyMatches;
use App\Enums\CivilStatus;
use App\Enums\Gender;
use App\Enums\RelativeKind;
use App\Http\Resources\FamilySuggestionsResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FamilySuggestionController extends Controller
{
    /**
     * Suggest likely relatives, and listed records the person may claim, from what is already on record.
     * Encoders only see matches from their own barangay.
     *
     * `limit` sets how many matches to return per role, and `rejected` lists the residents the person
     * already said "no" to, so the next best candidates are returned instead.
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
            'limit' => ['nullable', 'integer', 'between:1,12'],
            'rejected' => ['nullable', 'array'],
            'rejected.father' => ['nullable', 'array', 'max:100'],
            'rejected.father.*' => ['integer'],
            'rejected.mother' => ['nullable', 'array', 'max:100'],
            'rejected.mother.*' => ['integer'],
            'rejected.spouse' => ['nullable', 'array', 'max:100'],
            'rejected.spouse.*' => ['integer'],
            'rejected.children' => ['nullable', 'array', 'max:100'],
            'rejected.children.*' => ['integer'],
            'rejected.relatives' => ['nullable', 'array'],
            'rejected.relatives.*' => ['array', 'max:100'],
            'rejected.relatives.*.*' => ['integer'],
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

        $rejected = (array) ($input['rejected'] ?? []);
        $rejected['relatives'] = array_intersect_key((array) ($rejected['relatives'] ?? []), array_flip(RelativeKind::values()));

        return FamilySuggestionsResource::make($findFamilyMatches->handle($person, $ownBarangayId, null, [
            'limit' => (int) ($input['limit'] ?? 3),
            'rejected' => $rejected,
        ]));
    }
}
