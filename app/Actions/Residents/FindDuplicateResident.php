<?php

namespace App\Actions\Residents;

use App\Enums\ResidentStatus;
use App\Models\Resident;

/**
 * Finds a resident who is already on record with the same name and birthdate, so the same person is not registered
 * twice. One rule for both places that need it: the form check, and the check made again inside the transaction that
 * saves the registration, where two requests that arrive together can no longer both pass.
 */
class FindDuplicateResident
{
    /**
     * @param  int|null  $ignoreId  The record being claimed, which is the same person and not a duplicate of them.
     */
    public function handle(string $firstName, string $lastName, string $birthdate, ?int $ignoreId = null): ?Resident
    {
        return Resident::query()
            ->where('first_name', $firstName)
            ->where('last_name', $lastName)
            ->whereDate('birthdate', $birthdate)
            ->whereNot('status', ResidentStatus::Archived->value)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->first();
    }

    /**
     * What to tell the person who is registering.
     */
    public function message(Resident $existing): string
    {
        return $existing->status === ResidentStatus::Listed
            ? "{$existing->full_name} was already listed by a family member. Choose \"Yes, this is me\" to use that record."
            : "{$existing->full_name} born on {$existing->birthdate->format('M d, Y')} is already registered (ID {$existing->system_id_no}).";
    }
}
