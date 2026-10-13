<?php

namespace App\Http\Controllers;

use App\Actions\Residents\FindParentsThroughRelative;
use App\Actions\Residents\FindRelativesThroughRelatives;
use App\Enums\RelativeKind;
use App\Enums\ResidentStatus;
use App\Http\Resources\FamilySuggestionsResource;
use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RelativeSuggestionController extends Controller
{
    /**
     * Suggest a person's parents from the relatives they already know are registered
     * (a brother or sister, grandparent, uncle or aunt, cousin).
     */
    public function __invoke(Request $request, FindParentsThroughRelative $findParents, FindRelativesThroughRelatives $findRelatives): FamilySuggestionsResource
    {
        $input = $request->validate([
            'relatives' => ['required', 'array', 'min:1', 'max:30'],
            'relatives.*.id' => ['required', 'integer', Rule::exists('residents', 'id')->whereNot('status', ResidentStatus::Archived->value)],
            'relatives.*.relation' => ['required', Rule::in(RelativeKind::values())],
        ]);

        $residents = Resident::query()->whereIn('id', array_column($input['relatives'], 'id'))->get()->keyBy('id');

        $pairs = array_map(fn (array $entry): array => [
            'relative' => $residents->get((int) $entry['id']) ?? abort(422),
            'relation' => $entry['relation'],
        ], array_values($input['relatives']));

        $parents = $findParents->handleMany($pairs);
        $derived = $findRelatives->handle($pairs);

        return FamilySuggestionsResource::make([
            'claims' => [],
            'father' => $parents['father'],
            'mother' => $parents['mother'],
            'spouse' => [],
            'children' => [],
            'relatives' => $derived,
        ]);
    }
}
