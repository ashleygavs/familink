<?php

namespace App\Http\Controllers;

use App\Actions\Relief\FindRelationship;
use App\Enums\ResidentStatus;
use App\Models\Resident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @phpstan-type Person array{id: int, name: string, birth_year: int, household_code: string|null, barangay_name: string|null}
 */
class RelationshipController extends Controller
{
    /**
     * Says how two residents are related. The two are chosen with the search box and passed in the address, so a result
     * can be shared as a link.
     */
    public function index(Request $request, FindRelationship $find): Response
    {
        $input = $request->validate([
            'first' => ['nullable', 'integer'],
            'second' => ['nullable', 'integer', 'different:first'],
        ]);

        $first = isset($input['first']) ? $this->visible($request, (int) $input['first']) : null;
        $second = isset($input['second']) ? $this->visible($request, (int) $input['second']) : null;

        $result = null;

        if ($first !== null && $second !== null) {
            $found = $find->handle($first, $second);

            $result = [
                'related' => $found['second_is'] !== null,
                'second_is' => $found['second_is'] === null ? null : "{$second->full_name} is {$first->full_name}'s ".lcfirst($found['second_is']).'.',
                'first_is' => $found['first_is'] === null ? null : "{$first->full_name} is {$second->full_name}'s ".lcfirst($found['first_is']).'.',
            ];
        }

        return Inertia::render('reports/relationship', [
            'first' => $first === null ? null : $this->person($first),
            'second' => $second === null ? null : $this->person($second),
            'result' => $result,
        ]);
    }

    /**
     * Find residents by name or resident ID, among the ones the viewer may see.
     */
    public function search(Request $request): JsonResponse
    {
        $term = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q'];

        $query = Resident::query()
            ->visibleTo($request->user())
            ->with(['barangay', 'household'])
            ->whereNot('status', ResidentStatus::Archived->value);

        foreach (preg_split('/\s+/', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';

            $query->where(fn ($match) => $match
                ->where('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('suffix', 'like', $like)
                ->orWhere('system_id_no', 'like', $like));
        }

        return response()->json([
            'data' => $query->orderBy('last_name')->orderBy('first_name')->limit(10)->get()
                ->map(fn (Resident $resident): array => $this->person($resident))->values(),
        ]);
    }

    private function visible(Request $request, int $id): Resident
    {
        $resident = Resident::query()->visibleTo($request->user())->whereNot('status', ResidentStatus::Archived->value)->find($id);

        abort_if($resident === null, 403);

        return $resident;
    }

    /**
     * @return Person
     */
    private function person(Resident $resident): array
    {
        $resident->loadMissing(['barangay', 'household']);

        return [
            'id' => $resident->id,
            'name' => $resident->full_name,
            'birth_year' => $resident->birthdate->year,
            'household_code' => $resident->household?->household_code,
            'barangay_name' => $resident->barangay?->name,
        ];
    }
}
