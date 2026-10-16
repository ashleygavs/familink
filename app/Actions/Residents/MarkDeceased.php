<?php

namespace App\Actions\Residents;

use App\Enums\ResidentStatus;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records that a resident has died. They can no longer claim relief and are left out of eligibility counts, their
 * living spouse is marked Widowed, and if they headed a household someone who remains takes over. What they claimed
 * before stays on record.
 */
class MarkDeceased
{
    /**
     * @param  CarbonInterface|null  $on  The date of death, when it is known.
     * @param  int|null  $newHeadId  Who heads the household now, when the resident was the head and others remain.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Resident $resident, ?CarbonInterface $on = null, ?int $newHeadId = null): void
    {
        $this->guard($resident, $on, $newHeadId);

        DB::transaction(function () use ($actor, $resident, $on, $newHeadId): void {
            $resident->update(['is_deceased' => true, 'deceased_at' => $on?->toDateString()]);

            $household = $resident->household;

            if ($household !== null && $household->head_resident_id === $resident->id) {
                $household->passHeadTo($newHeadId === null ? null : Resident::query()->find($newHeadId));
            }

            $spouse = $resident->spouse;

            if ($spouse !== null && ! $spouse->is_deceased) {
                $spouse->update(['civil_status' => 'Widowed']);

                ResidentChange::query()->create([
                    'resident_id' => $spouse->id,
                    'changed_by_user_id' => $actor->id,
                    'type' => ResidentChange::WIDOWED,
                    'summary' => "Recorded as widowed after the death of {$resident->full_name}.",
                    'data' => ['spouse_id' => $resident->id],
                ]);
            }

            ResidentChange::query()->create([
                'resident_id' => $resident->id,
                'changed_by_user_id' => $actor->id,
                'type' => ResidentChange::DECEASED,
                'summary' => $on === null ? 'Recorded as deceased.' : "Recorded as deceased on {$on->format('M j, Y')}.",
                'data' => ['deceased_at' => $on?->toDateString(), 'new_head_id' => $newHeadId],
            ]);
        });
    }

    /**
     * @throws ValidationException
     */
    private function guard(Resident $resident, ?CarbonInterface $on, ?int $newHeadId): void
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if ($resident->status === ResidentStatus::Archived) {
            $fail('deceased_at', 'An archived registration cannot be changed.');
        }

        if ($resident->is_deceased) {
            $fail('deceased_at', "{$resident->full_name} is already recorded as deceased.");
        }

        if ($on !== null && $on->lessThan($resident->birthdate)) {
            $fail('deceased_at', 'The date of death cannot be before the date of birth.');
        }

        $household = $resident->household;

        if ($household === null || $household->head_resident_id !== $resident->id) {
            return;
        }

        $remaining = Resident::query()
            ->where('household_id', $household->id)
            ->whereKeyNot($resident->id)
            ->whereNot('status', ResidentStatus::Archived->value)
            ->where('is_deceased', false)
            ->pluck('id');

        if ($remaining->isNotEmpty() && ($newHeadId === null || ! $remaining->contains($newHeadId))) {
            $fail('new_head_id', 'This resident heads their household. Choose who becomes the new head.');
        }
    }
}
