<?php

namespace App\Actions\Relief;

use App\Enums\AllocationType;
use App\Enums\EventStatus;
use App\Enums\ResidentStatus;
use App\Models\Household;
use App\Models\ReliefEvent;
use App\Models\ReliefLog;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a scanned code may claim relief and records the claim. A denial is a normal result with a
 * plain reason, not an error.
 *
 * @phpstan-type ScanResult array{granted: bool, code: string, message: string, resident: array<string, string|null>|null, previous: array<string, string>|null}
 */
class ScanRelief
{
    /**
     * @return ScanResult
     */
    public function handle(User $user, ReliefEvent $event, string $code): array
    {
        if (! $event->isOpenForScanning()) {
            return $this->deny('event_closed', $this->closedMessage($event));
        }

        $resident = $this->find(trim($code));

        if ($resident === null) {
            return $this->deny('unknown_code', 'This code is not registered in FamiLink.');
        }

        if (! $this->inArea($user, $event, $resident)) {
            return $this->deny('outside_area', "{$resident->full_name} belongs to another barangay or municipality, so they cannot claim here.", $resident);
        }

        if ($resident->is_deceased) {
            return $this->deny('deceased', "{$resident->full_name} is recorded as deceased.", $resident);
        }

        if ($resident->status !== ResidentStatus::Approved) {
            return $this->deny('not_approved', $this->notApprovedMessage($resident), $resident);
        }

        return $this->claim($user, $event, $resident);
    }

    /**
     * Record the claim in a transaction. The household row is locked so two scanners cannot both pass the
     * household check, and the unique index on event and resident backs up the per-person check.
     *
     * @return ScanResult
     */
    private function claim(User $user, ReliefEvent $event, Resident $resident): array
    {
        try {
            return DB::transaction(function () use ($user, $event, $resident): array {
                Household::query()->whereKey($resident->household_id)->lockForUpdate()->first();

                $existing = ReliefLog::query()
                    ->with(['resident', 'scannedBy'])
                    ->where('event_id', $event->id)
                    ->when(
                        $event->allocation_type === AllocationType::PerHousehold,
                        fn ($query) => $query->where('household_id', $resident->household_id),
                        fn ($query) => $query->where('resident_id', $resident->id),
                    )
                    ->first();

                if ($existing !== null) {
                    return $this->alreadyClaimed($event, $resident, $existing);
                }

                $limit = $event->claimLimitPerHousehold();

                if ($limit !== null && ReliefLog::query()->where('event_id', $event->id)->where('household_id', $resident->household_id)->count() >= $limit) {
                    return $this->deny('household_limit', "This household already used all {$limit} claims allowed for this event.", $resident);
                }

                ReliefLog::query()->create([
                    'event_id' => $event->id,
                    'resident_id' => $resident->id,
                    'household_id' => $resident->household_id,
                    'scanned_by_user_id' => $user->id,
                    'status' => 'Claimed',
                ]);

                return $this->result(true, 'granted', "{$resident->full_name} may receive relief.", $resident);
            });
        } catch (UniqueConstraintViolationException) {
            $existing = ReliefLog::query()->with(['resident', 'scannedBy'])
                ->where('event_id', $event->id)->where('resident_id', $resident->id)->first();

            return $existing === null
                ? $this->deny('already_claimed', 'This resident already claimed relief.', $resident)
                : $this->alreadyClaimed($event, $resident, $existing);
        }
    }

    /**
     * @return ScanResult
     */
    private function alreadyClaimed(ReliefEvent $event, Resident $resident, ReliefLog $existing): array
    {
        $byHousehold = $event->allocation_type === AllocationType::PerHousehold && $existing->resident_id !== $resident->id;
        $who = $existing->resident->full_name ?? 'a household member';
        $when = $existing->created_at?->format('M j, Y g:i A') ?? '';

        $message = $byHousehold
            ? "This household already claimed relief. {$who} claimed it on {$when}."
            : "{$resident->full_name} already claimed relief on {$when}.";

        $result = $this->deny($byHousehold ? 'household_claimed' : 'already_claimed', $message, $resident);
        $result['previous'] = [
            'name' => $who,
            'claimed_at' => $when,
            'scanned_by' => $existing->scannedBy->name ?? '',
        ];

        return $result;
    }

    /**
     * A QR code, or a typed resident ID for a damaged code.
     */
    private function find(string $code): ?Resident
    {
        if ($code === '') {
            return null;
        }

        return Resident::query()
            ->with(['barangay', 'household'])
            ->where(fn ($query) => $query->where('qr_code_hash', $code)->orWhere('system_id_no', strtoupper($code)))
            ->first();
    }

    private function inArea(User $user, ReliefEvent $event, Resident $resident): bool
    {
        return Resident::query()->visibleTo($user)->whereKey($resident->id)->exists()
            && $resident->barangay?->municipality_id === $event->municipality_id;
    }

    private function closedMessage(ReliefEvent $event): string
    {
        return match (true) {
            $event->status === EventStatus::Draft => 'This relief event is still a draft and is not open yet.',
            $event->status === EventStatus::Closed => 'This relief event is closed.',
            default => "This relief event is only open from {$event->start_date->format('M j, Y')} to {$event->end_date->format('M j, Y')}.",
        };
    }

    private function notApprovedMessage(Resident $resident): string
    {
        return match ($resident->status) {
            ResidentStatus::Pending => "{$resident->full_name} is still waiting for approval, so they cannot claim yet.",
            ResidentStatus::Listed => "{$resident->full_name} was only listed by a relative and has not registered yet.",
            default => "{$resident->full_name} is archived and cannot claim relief.",
        };
    }

    /**
     * @return ScanResult
     */
    private function deny(string $code, string $message, ?Resident $resident = null): array
    {
        return $this->result(false, $code, $message, $resident);
    }

    /**
     * @return ScanResult
     */
    private function result(bool $granted, string $code, string $message, ?Resident $resident): array
    {
        return [
            'granted' => $granted,
            'code' => $code,
            'message' => $message,
            'resident' => $resident === null ? null : [
                'full_name' => $resident->full_name,
                'system_id_no' => $resident->system_id_no,
                'barangay_name' => $resident->barangay?->name,
                'household_code' => $resident->household?->household_code,
            ],
            'previous' => null,
        ];
    }
}
