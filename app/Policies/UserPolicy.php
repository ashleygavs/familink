<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole(RoleName::SuperAdmin, RoleName::Admin);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    /**
     * A super admin may edit anyone. An admin may only edit encoders of the barangays they handle,
     * so an admin can never touch another admin, a super admin or their own role.
     */
    public function update(User $actor, User $target): bool
    {
        if ($actor->hasRole(RoleName::SuperAdmin)) {
            return true;
        }

        return $actor->hasRole(RoleName::Admin)
            && $target->hasRole(RoleName::User)
            && $actor->canAccessBarangay($target->barangay_id);
    }

    /**
     * Whoever may edit an account may switch it off and on again, except their own and the last active
     * super admin, so nobody is locked out of the system.
     */
    public function deactivate(User $actor, User $target): bool
    {
        return $actor->isNot($target) && $this->update($actor, $target);
    }

    public function reactivate(User $actor, User $target): bool
    {
        return $this->update($actor, $target);
    }

    /**
     * Only a super admin deletes accounts, and never their own.
     */
    public function delete(User $actor, User $target): bool
    {
        return $actor->hasRole(RoleName::SuperAdmin) && $actor->isNot($target);
    }
}
