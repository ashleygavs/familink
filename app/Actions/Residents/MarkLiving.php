<?php

namespace App\Actions\Residents;

use App\Enums\ResidentStatus;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a death that was recorded by mistake. A spouse who was marked Widowed because of it goes back to Married.
 * The household head is not restored: that needs a household change.
 */
class MarkLiving
{
    /**
     * @throws ValidationException
     */
    public function handle(User $actor, Resident $resident): void
    {
        if ($resident->status === ResidentStatus::Archived || ! $resident->is_deceased) {
            throw ValidationException::withMessages(['deceased_at' => "{$resident->full_name} is not recorded as deceased."]);
        }

        DB::transaction(function () use ($actor, $resident): void {
            $resident->update(['is_deceased' => false, 'deceased_at' => null]);

            $spouse = $resident->spouse;

            if ($spouse !== null && ! $spouse->is_deceased && $spouse->spouse_id === $resident->id && $spouse->civil_status === 'Widowed') {
                $spouse->update(['civil_status' => 'Married']);

                ResidentChange::query()->create([
                    'resident_id' => $spouse->id,
                    'changed_by_user_id' => $actor->id,
                    'type' => ResidentChange::WIDOWED,
                    'summary' => "Married again on record: the death of {$resident->full_name} was a mistake.",
                    'data' => ['spouse_id' => $resident->id],
                ]);
            }

            ResidentChange::query()->create([
                'resident_id' => $resident->id,
                'changed_by_user_id' => $actor->id,
                'type' => ResidentChange::LIVING,
                'summary' => 'Recorded as living again. The death was entered by mistake.',
                'data' => null,
            ]);
        });
    }
}
