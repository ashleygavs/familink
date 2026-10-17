<?php

namespace App\Actions\Residents;

use App\Enums\CivilStatus;
use App\Enums\ResidentStatus;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ends the marriage of two living residents, by annulment or legal separation. They stop being each other's
 * spouse and are free to marry again. Their children stay linked to both of them, and so does their household,
 * which is changed separately. Someone who took a spouse's surname can go back to their maiden name.
 */
class EndMarriage
{
    /**
     * @param  CivilStatus  $status  Separated or Annulled.
     * @param  list<int>  $restoreMaidenNameIds  The people who go back to their maiden name.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Resident $resident, CivilStatus $status, array $restoreMaidenNameIds = []): void
    {
        $spouse = $resident->spouse;

        $this->guard($actor, $resident, $spouse, $status, $restoreMaidenNameIds);

        DB::transaction(function () use ($actor, $resident, $spouse, $status, $restoreMaidenNameIds): void {
            foreach ([[$resident, $spouse], [$spouse, $resident]] as [$person, $partner]) {
                $updates = ['spouse_id' => null, 'civil_status' => $status->value];

                if (in_array($person->id, $restoreMaidenNameIds, true) && $this->canRestoreMaidenName($person)) {
                    $updates['last_name'] = $person->maiden_name;
                    $updates['maiden_name'] = null;
                }

                $before = $person->last_name;
                $person->update($updates);

                ResidentChange::query()->create([
                    'resident_id' => $person->id,
                    'changed_by_user_id' => $actor->id,
                    'type' => ResidentChange::MARRIAGE_ENDED,
                    'summary' => "Marriage to {$partner->full_name} ended: {$status->value}."
                        .($before !== $person->last_name ? " Went back to the surname {$person->last_name} (was {$before})." : ''),
                    'data' => ['spouse_id' => $partner->id, 'civil_status' => $status->value, 'last_name_before' => $before, 'last_name_after' => $person->last_name],
                ]);
            }
        });
    }

    /**
     * Whether the resident has a maiden name that differs from the surname they use now.
     */
    public function canRestoreMaidenName(Resident $resident): bool
    {
        return filled($resident->maiden_name)
            && Resident::normalizeName($resident->maiden_name) !== $resident->last_name_key;
    }

    /**
     * @param  list<int>  $restoreMaidenNameIds
     *
     * @throws ValidationException
     */
    private function guard(User $actor, Resident $resident, ?Resident $spouse, CivilStatus $status, array $restoreMaidenNameIds): void
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if (! in_array($status, [CivilStatus::Separated, CivilStatus::Annulled], true)) {
            $fail('civil_status', 'Choose Separated or Annulled.');
        }

        if ($spouse === null) {
            $fail('civil_status', "{$resident->full_name} has no spouse on record.");
        }

        foreach ([$resident, $spouse] as $person) {
            if ($person->status === ResidentStatus::Archived) {
                $fail('civil_status', 'An archived registration cannot be changed.');
            }

            if ($person->is_deceased) {
                $fail('civil_status', "{$person->full_name} is recorded as deceased, so they are widowed and there is no marriage to end.");
            }
        }

        if (! Resident::query()->visibleTo($actor)->whereKey($spouse->id)->exists()) {
            $fail('civil_status', 'The spouse is outside the barangays you handle. Ask the admin of that barangay, or the super admin.');
        }

        if (array_diff($restoreMaidenNameIds, [$resident->id, $spouse->id]) !== []) {
            $fail('restore_maiden_name_ids', 'Choose only the two people in this marriage.');
        }
    }
}
