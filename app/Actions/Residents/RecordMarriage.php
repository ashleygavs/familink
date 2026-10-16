<?php

namespace App\Actions\Residents;

use App\Enums\ResidentStatus;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records that two residents who are already registered have married: they become each other's spouse and
 * both are marked Married. One of them may take the other's surname, keeping the old one as a maiden name.
 */
class RecordMarriage
{
    /** The youngest age at which a marriage can be recorded, the same limit the family suggestions use. */
    private const MINIMUM_AGE = 16;

    /**
     * @param  int|null  $takesSurnameId  The resident who now uses the other's surname, or null for neither.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Resident $first, Resident $second, ?int $takesSurnameId = null): void
    {
        $this->guard($first, $second, $takesSurnameId);

        DB::transaction(function () use ($actor, $first, $second, $takesSurnameId): void {
            $before = [
                $first->id => ['civil_status' => $first->civil_status, 'last_name' => $first->last_name],
                $second->id => ['civil_status' => $second->civil_status, 'last_name' => $second->last_name],
            ];

            // Both are updated first, so each history line names the other person as they are recorded now.
            foreach ([[$first, $second], [$second, $first]] as [$person, $partner]) {
                $updates = ['spouse_id' => $partner->id, 'civil_status' => 'Married'];

                if ($takesSurnameId === $person->id && $person->last_name_key !== $partner->last_name_key) {
                    $updates['maiden_name'] = $person->maiden_name ?: $person->last_name;
                    $updates['last_name'] = $partner->last_name;
                }

                $person->update($updates);
            }

            foreach ([[$first, $second], [$second, $first]] as [$person, $partner]) {
                ResidentChange::query()->create([
                    'resident_id' => $person->id,
                    'changed_by_user_id' => $actor->id,
                    'type' => ResidentChange::MARRIAGE,
                    'summary' => $this->summary($person, $partner, $before[$person->id]['last_name']),
                    'data' => [
                        'spouse_id' => $partner->id,
                        'civil_status_before' => $before[$person->id]['civil_status'],
                        'last_name_before' => $before[$person->id]['last_name'],
                        'last_name_after' => $person->last_name,
                    ],
                ]);
            }
        });
    }

    private function summary(Resident $person, Resident $partner, string $previousSurname): string
    {
        $text = "Married to {$partner->full_name}.";

        if ($person->last_name !== $previousSurname) {
            $text .= " Now uses the surname {$person->last_name} (was {$previousSurname}).";
        }

        return $text;
    }

    /**
     * @throws ValidationException
     */
    private function guard(Resident $first, Resident $second, ?int $takesSurnameId): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['spouse_id' => $message]);

        if ($first->is($second)) {
            $fail('A person cannot marry themselves.');
        }

        foreach ([$first, $second] as $person) {
            if ($person->status !== ResidentStatus::Approved || $person->is_deceased) {
                $fail("{$person->full_name} must be an approved, living resident.");
            }

            if ($person->spouse_id !== null && ! $person->spouse?->is_deceased) {
                $fail("{$person->full_name} already has a spouse on record.");
            }

            if ($person->birthdate->age < self::MINIMUM_AGE) {
                $fail("{$person->full_name} is too young to be recorded as married.");
            }
        }

        if ($this->areCloseRelatives($first, $second)) {
            $fail("{$first->full_name} and {$second->full_name} are recorded as parent and child or as brother and sister.");
        }

        if ($takesSurnameId !== null && ! in_array($takesSurnameId, [$first->id, $second->id], true)) {
            throw ValidationException::withMessages(['takes_surname_id' => 'Choose one of the two people.']);
        }
    }

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
}
