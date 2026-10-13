<?php

namespace App\Http\Resources;

use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Presents the result of FindFamilyMatches for the frontend.
 *
 * @property array{claims: list<array{resident: Resident, reasons: list<string>}>, father: list<array{resident: Resident, score: int, confidence: string, reasons: list<string>}>, mother: list<array{resident: Resident, score: int, confidence: string, reasons: list<string>}>, spouse: list<array{resident: Resident, score: int, confidence: string, reasons: list<string>}>, children: list<array{resident: Resident, score: int, confidence: string, reasons: list<string>}>, relatives: list<array{resident: Resident, relation: string, score: int, confidence: string, reasons: list<string>}>} $resource
 */
class FamilySuggestionsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $match = fn (array $match): array => [
            'resident' => ResidentOptionResource::make($match['resident'])->resolve(),
            'score' => $match['score'],
            'confidence' => $match['confidence'],
            'reasons' => $match['reasons'],
        ];

        return [
            'claims' => array_map(fn (array $claim): array => [
                'resident' => [
                    ...ResidentOptionResource::make($claim['resident'])->resolve(),
                    'listed_by' => $claim['resident']->listedBy?->full_name,
                    'father_id' => $claim['resident']->father_id,
                    'mother_id' => $claim['resident']->mother_id,
                    'father_name' => $claim['resident']->father?->full_name,
                    'mother_name' => $claim['resident']->mother?->full_name,
                    'spouse_name' => $claim['resident']->spouse?->full_name,
                    'children_names' => $claim['resident']->childrenAsFather
                        ->merge($claim['resident']->childrenAsMother)
                        ->map(fn (Resident $child) => $child->full_name)
                        ->unique()
                        ->values()
                        ->all(),
                ],
                'reasons' => $claim['reasons'],
            ], $this->resource['claims']),
            'father' => array_map($match, $this->resource['father']),
            'mother' => array_map($match, $this->resource['mother']),
            'spouse' => array_map($match, $this->resource['spouse']),
            'children' => array_map($match, $this->resource['children']),
            'relatives' => array_map(fn (array $relative): array => [...$match($relative), 'relation' => $relative['relation']], $this->resource['relatives']),
        ];
    }
}
