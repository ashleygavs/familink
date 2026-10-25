<?php

namespace App\Actions\Residents;

use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkRelatives
{
    public function __construct(
        private AdoptDependents $adoptDependents,
        private MarriageRules $marriageRules,
        private RecordResidentChange $record,
    ) {}

    /**
     * Fill the empty family slots of a resident (father, mother, spouse) and adopt children.
     * Slots that already hold a link are left untouched. Linking a spouse makes both people married, and is refused
     * when the other person cannot be linked, rather than leaving a link on one side only. When an author is given,
     * every record that changed gets a line in its history.
     *
     * @param  array<string, mixed>  $links  Any of father_id, mother_id, spouse_id, dependent_ids.
     *
     * @throws ValidationException
     */
    public function handle(Resident $resident, array $links, ?User $actor = null): void
    {
        DB::transaction(function () use ($resident, $links, $actor): void {
            $updates = [];

            foreach (['father_id', 'mother_id', 'spouse_id'] as $slot) {
                if (! empty($links[$slot]) && $resident->{$slot} === null) {
                    $updates[$slot] = (int) $links[$slot];
                }
            }

            $spouse = isset($updates['spouse_id']) ? $this->spouseToLink($updates['spouse_id']) : null;
            $ownCivilStatusBefore = $resident->civil_status;
            $partnerCivilStatusBefore = $spouse?->civil_status;

            if ($updates !== []) {
                $resident->update($updates);
            }

            $this->recordParents($resident, $updates, $actor);

            if ($spouse !== null) {
                $spouse->update(['spouse_id' => $resident->id, 'civil_status' => 'Married']);
                $resident->update(['civil_status' => 'Married']);

                if ($actor !== null) {
                    $this->record->handle($resident, $actor, ResidentChange::FAMILY_LINKED, "Linked {$spouse->full_name} as spouse.", ['kind' => 'spouse', 'partner_id' => $spouse->id, 'civil_status_before' => $ownCivilStatusBefore]);
                    $this->record->handle($spouse, $actor, ResidentChange::FAMILY_LINKED, "Linked {$resident->full_name} as spouse.", ['kind' => 'spouse', 'partner_id' => $resident->id, 'civil_status_before' => $partnerCivilStatusBefore]);
                }
            }

            $dependentIds = array_values((array) ($links['dependent_ids'] ?? []));

            if ($dependentIds !== [] && $resident->household_id !== null) {
                $household = Household::query()->findOrFail($resident->household_id);

                $this->adoptDependents->handle($resident, $household, $dependentIds);
                $this->recordChildren($resident, $household, $dependentIds, $actor);
            }
        });
    }

    /**
     * @param  array<string, int>  $updates
     */
    private function recordParents(Resident $resident, array $updates, ?User $actor): void
    {
        if ($actor === null) {
            return;
        }

        foreach (['father_id' => 'father', 'mother_id' => 'mother'] as $slot => $label) {
            $parent = isset($updates[$slot]) ? Resident::query()->find($updates[$slot]) : null;

            if ($parent !== null) {
                $this->record->handle($resident, $actor, ResidentChange::FAMILY_LINKED, "Linked {$parent->full_name} as {$label}.", ['kind' => $label, 'relative_id' => $parent->id]);
            }
        }
    }

    /**
     * @param  list<int|string>  $dependentIds
     */
    private function recordChildren(Resident $parent, Household $household, array $dependentIds, ?User $actor): void
    {
        if ($actor === null) {
            return;
        }

        $role = $parent->gender === 'Male' ? 'father' : 'mother';

        foreach (Resident::query()->whereIn('id', $dependentIds)->get() as $child) {
            $this->record->handle($parent, $actor, ResidentChange::FAMILY_LINKED, "Linked {$child->full_name} as child.", ['kind' => 'child', 'relative_id' => $child->id]);
            $this->record->handle($child, $actor, ResidentChange::FAMILY_LINKED, "Linked {$parent->full_name} as {$role} and moved into household {$household->household_code}.", ['kind' => $role, 'relative_id' => $parent->id, 'household_id' => $household->id]);
        }
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
