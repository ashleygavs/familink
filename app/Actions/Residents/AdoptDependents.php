<?php

namespace App\Actions\Residents;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\ReliefLog;
use App\Models\Resident;

class AdoptDependents
{
    /**
     * Link existing residents as children of a parent and move them into the parent's household.
     *
     * @param  list<int|string>  $dependentIds
     */
    public function handle(Resident $parent, Household $household, array $dependentIds): void
    {
        if ($dependentIds === []) {
            return;
        }

        $parentColumn = $parent->gender === 'Male' ? 'father_id' : 'mother_id';
        $children = Resident::query()->whereIn('id', $dependentIds)->lockForUpdate()->get();
        $previousHouseholdIds = $children->pluck('household_id')->filter()->unique()->diff([$household->id]);

        foreach ($children as $child) {
            $child->update([
                $parentColumn => $parent->id,
                'household_id' => $household->id,
                'barangay_id' => $household->barangay_id,
                'household_role' => HouseholdRole::Child->value,
            ]);
        }

        foreach ($previousHouseholdIds as $householdId) {
            $this->tidyPreviousHousehold(Household::query()->findOrFail((int) $householdId), array_values($children->modelKeys()));
        }
    }

    /**
     * Clear a head who moved away and remove the household if nobody lives there and it has no relief history.
     *
     * @param  list<int|string>  $movedResidentIds
     */
    private function tidyPreviousHousehold(Household $household, array $movedResidentIds): void
    {
        if (in_array($household->head_resident_id, array_map('intval', $movedResidentIds), true)) {
            $household->update(['head_resident_id' => null]);
        }

        if (! $household->residents()->exists() && ! ReliefLog::query()->where('household_id', $household->id)->exists()) {
            $household->delete();
        }
    }
}
