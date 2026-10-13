<?php

namespace App\Http\Controllers;

use App\Actions\Residents\FindBloodRelatives;
use App\Http\Resources\ResidentOptionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FamilyRelativesController extends Controller
{
    /**
     * Siblings, grandparents, uncles, aunts and cousins that follow from the chosen parents.
     */
    public function __invoke(Request $request, FindBloodRelatives $findBloodRelatives): JsonResponse
    {
        $input = $request->validate([
            'father_id' => ['nullable', 'integer', Rule::exists('residents', 'id')],
            'mother_id' => ['nullable', 'integer', Rule::exists('residents', 'id')],
            'self_id' => ['nullable', 'integer'],
        ]);

        $relatives = $findBloodRelatives->handle(
            isset($input['father_id']) ? (int) $input['father_id'] : null,
            isset($input['mother_id']) ? (int) $input['mother_id'] : null,
            isset($input['self_id']) ? (int) $input['self_id'] : null,
        );

        $present = fn (array $group): array => array_map(fn (array $relative): array => [
            'resident' => ResidentOptionResource::make($relative['resident'])->resolve(),
            'relation' => $relative['relation'],
        ], $group);

        return response()->json(['data' => array_map($present, $relatives)]);
    }
}
