<?php

namespace App\Actions\Residents;

use App\Enums\EventStatus;
use App\Enums\HouseholdRole;
use App\Enums\ResidentStatus;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\ReliefEvent;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a resident to another household, an existing one or a new one. Relief already claimed stays with the
 * household that claimed it; only claims from now on count for the new household.
 */
class MoveHousehold
{
    public function __construct(private GenerateIdentifiers $identifiers) {}

    /**
     * @param  array{household_id?: int|null, street_address?: string|null, household_role?: string|null, is_head?: bool, new_head_id?: int|null}  $target  Either household_id (an existing household) or street_address (a new one).
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Resident $resident, array $target): Household
    {
        $old = $resident->household;
        $isHead = (bool) ($target['is_head'] ?? false);
        $role = $isHead ? HouseholdRole::Head : $this->role($target['household_role'] ?? null);
        $destination = isset($target['household_id']) ? Household::query()->findOrFail($target['household_id']) : null;

        $this->guard($resident, $old, $destination, $isHead, $target);

        return DB::transaction(function () use ($actor, $resident, $old, $destination, $isHead, $role, $target): Household {
            $barangay = $destination !== null
                ? Barangay::query()->findOrFail($destination->barangay_id)
                : Barangay::query()->findOrFail($resident->barangay_id);

            $new = $destination ?? Household::query()->create([
                'household_code' => $this->identifiers->householdCode($barangay),
                'barangay_id' => $barangay->id,
                'street_address' => trim((string) ($target['street_address'] ?? '')),
            ]);

            $this->leave($resident, $old, $target['new_head_id'] ?? null);

            $resident->update(['household_id' => $new->id, 'barangay_id' => $barangay->id, 'household_role' => $role->value]);

            if ($isHead) {
                $new->update(['head_resident_id' => $resident->id]);
            }

            ResidentChange::query()->create([
                'resident_id' => $resident->id,
                'changed_by_user_id' => $actor->id,
                'type' => ResidentChange::HOUSEHOLD_MOVE,
                'summary' => $this->summary($old, $new, $role, $destination === null),
                'data' => [
                    'from_household_id' => $old?->id,
                    'to_household_id' => $new->id,
                    'role' => $role->value,
                    'new_household' => $destination === null,
                ],
            ]);

            return $new;
        });
    }

    /**
     * The titles of relief events that are open right now where the resident lives, or where they would move to.
     * Households cannot change while one is open: splitting a household during a distribution would let it
     * claim more than once.
     *
     * @return list<string>
     */
    public function blockingEvents(Resident $resident, ?Household $destination = null): array
    {
        $barangayIds = array_filter([$resident->barangay_id, $destination?->barangay_id]);
        $municipalityIds = Barangay::query()->whereIn('id', $barangayIds)->pluck('municipality_id')->unique()->all();

        return array_values(ReliefEvent::query()
            ->whereIn('municipality_id', $municipalityIds)
            ->where('status', EventStatus::Active->value)
            ->get()
            ->filter(fn (ReliefEvent $event): bool => $event->isOpenForScanning())
            ->map(fn (ReliefEvent $event): string => $event->title)
            ->all());
    }

    /**
     * Take the resident out of their old household, handing the head's place to someone who stays.
     */
    private function leave(Resident $resident, ?Household $old, mixed $newHeadId): void
    {
        if ($old === null || $old->head_resident_id !== $resident->id) {
            return;
        }

        $successor = $newHeadId === null ? null : Resident::query()->whereKey((int) $newHeadId)->first();

        $old->update(['head_resident_id' => $successor?->id]);
        $successor?->update(['household_role' => HouseholdRole::Head->value]);
    }

    private function summary(?Household $old, Household $new, HouseholdRole $role, bool $created): string
    {
        $from = $old === null ? 'no household' : "{$old->household_code} ({$old->street_address})";
        $to = $created ? "a new household {$new->household_code} ({$new->street_address})" : "{$new->household_code} ({$new->street_address})";

        return "Moved from {$from} to {$to} as {$role->value}.";
    }

    private function role(?string $role): HouseholdRole
    {
        return HouseholdRole::tryFrom((string) $role) ?? HouseholdRole::Other;
    }

    /**
     * @param  array<string, mixed>  $target
     *
     * @throws ValidationException
     */
    private function guard(Resident $resident, ?Household $old, ?Household $destination, bool $isHead, array $target): void
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        $open = $this->blockingEvents($resident, $destination);

        if ($open !== []) {
            $fail('household_id', 'Households cannot be changed while a relief event is open ('.implode(', ', $open).'). Try again after it closes.');
        }

        if ($resident->status === ResidentStatus::Archived || $resident->status === ResidentStatus::Listed) {
            $fail('household_id', 'Only registered residents can be moved to another household.');
        }

        if ($destination === null && trim((string) ($target['street_address'] ?? '')) === '') {
            $fail('street_address', 'Enter the address of the new household.');
        }

        if ($destination !== null && $destination->id === $old?->id) {
            $fail('household_id', 'This resident already lives in that household.');
        }

        if ($destination?->head_resident_id !== null && $isHead && $destination->head_resident_id !== $resident->id) {
            $fail('is_head', "{$destination->household_code} already has a head.");
        }

        if ($old !== null && $old->head_resident_id === $resident->id) {
            $remaining = Resident::query()
                ->where('household_id', $old->id)
                ->whereKeyNot($resident->id)
                ->whereNot('status', ResidentStatus::Archived->value)
                ->pluck('id');

            $chosen = $target['new_head_id'] ?? null;

            if ($remaining->isNotEmpty() && ($chosen === null || ! $remaining->contains((int) $chosen))) {
                $fail('new_head_id', 'This resident heads the household they are leaving. Choose who becomes the new head.');
            }
        }
    }
}
