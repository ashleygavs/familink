<?php

namespace App\Actions\Residents;

use App\Enums\ResidentStatus;
use App\Models\Resident;

/**
 * The rules about who can be someone's husband or wife, in one place. They are used when a marriage is recorded and
 * when a spouse is linked while registering or approving someone, so the two can never disagree.
 */
class MarriageRules
{
    /**
     * Parents and children, and brothers and sisters, cannot be recorded as married.
     */
    public function areCloseRelatives(Resident $first, Resident $second): bool
    {
        $parentAndChild = in_array($second->id, [$first->father_id, $first->mother_id], true)
            || in_array($first->id, [$second->father_id, $second->mother_id], true);

        $siblings = ($first->father_id !== null && $first->father_id === $second->father_id)
            || ($first->mother_id !== null && $first->mother_id === $second->mother_id);

        return $parentAndChild || $siblings;
    }

    /**
     * Whether the person is married to someone who is alive. A widowed person's old link does not count.
     */
    public function hasLivingSpouse(Resident $person): bool
    {
        return $person->spouse_id !== null && ! $person->spouse?->is_deceased;
    }

    /**
     * Why a resident cannot be linked as someone's husband or wife, or null when they can. A person who is still
     * waiting for approval can be linked, because a couple may register together.
     */
    public function linkProblem(Resident $spouse): ?string
    {
        if ($spouse->is_deceased || $spouse->status === ResidentStatus::Archived) {
            return "{$spouse->full_name} cannot be linked as a husband or wife because they are archived or recorded as deceased.";
        }

        if ($this->hasLivingSpouse($spouse)) {
            return "{$spouse->full_name} already has a spouse on record.";
        }

        return null;
    }
}
