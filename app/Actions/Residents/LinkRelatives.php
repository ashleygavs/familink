<?php

namespace App\Actions\Residents;

use App\Models\Household;
use App\Models\Resident;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkRelatives
{
    public function __construct(private AdoptDependents $adoptDependents, private MarriageRules $marriageRules) {}

    /**
     * Fill the empty family slots of a resident (father, mother, spouse) and adopt children.
     * Slots that already hold a link are left untouched. Linking a spouse makes both people married, and is refused
     * when the other person cannot be linked, rather than leaving a link on one side only.
     *
     * @param  array<string, mixed>  $links  Any of father_id, mother_id, spouse_id, dependent_ids.
     *
     * @throws ValidationException
     */
    public function handle(Resident $resident, array $links): void
    {
        DB::transaction(function () use ($resident, $links): void {
            $updates = [];

            foreach (['father_id', 'mother_id', 'spouse_id'] as $slot) {
                if (! empty($links[$slot]) && $resident->{$slot} === null) {
                    $updates[$slot] = (int) $links[$slot];
                }
            }

            $spouse = isset($updates['spouse_id']) ? $this->spouseToLink($updates['spouse_id']) : null;

            if ($updates !== []) {
                $resident->update($updates);
            }

            if ($spouse !== null) {
                $spouse->update(['spouse_id' => $resident->id, 'civil_status' => 'Married']);
                $resident->update(['civil_status' => 'Married']);
            }

            $dependentIds = array_values((array) ($links['dependent_ids'] ?? []));

            if ($dependentIds !== [] && $resident->household_id !== null) {
                $this->adoptDependents->handle($resident, Household::query()->findOrFail($resident->household_id), $dependentIds);
            }
        });
    }

    /**
     * The person about to be linked as a husband or wife, read again under a lock, since someone else may have married
     * them since the form was checked.
     *
     * @throws ValidationException
     */
    private function spouseToLink(int $id): Resident
    {
        $spouse = Resident::query()->lockForUpdate()->findOrFail($id);
        $problem = $this->marriageRules->linkProblem($spouse);

        if ($problem !== null) {
            throw ValidationException::withMessages(['spouse_id' => $problem]);
        }

        return $spouse;
    }
}
