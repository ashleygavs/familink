<?php

namespace App\Actions\Relief;

use App\Enums\AllocationType;
use App\Enums\EventStatus;
use App\Enums\ResidentStatus;
use App\Models\Household;
use App\Models\ReliefEvent;
use App\Models\ReliefLog;
use App\Models\Resident;
use App\Models\RetiredQrCode;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a scanned code may claim relief and records the claim. A denial is a normal result with a
 * plain reason, not an error.
 *
 * @phpstan-type ScanResult array{granted: bool, code: string, message: string, warning: string|null, resident: array<string, string|null>|null, previous: array<string, string|null>|null}
 * @phpstan-type Representative array{name: string, relationship: string, verified: bool}
 */
class ScanRelief
{
    /** After this many earlier claims by the same representative in one event, the next one comes with a warning. */
    private const REPRESENTATIVE_WARNING_AFTER = 2;

    /**
     * @param  Representative|null  $representative  Set when someone other than the resident collects the relief.
     * @return ScanResult
     */
    public function handle(User $user, ReliefEvent $event, string $code, ?array $representative = null): array
    {
        if (! $event->isOpenForScanning()) {
            return $this->deny('event_closed', $this->closedMessage($event));
        }

        $code = trim($code);
        [$resident, $method] = $this->find($code);

        if ($resident === null) {
            $retired = $this->retired($code);

            return $retired === null
                ? $this->deny('unknown_code', 'This code is not registered in FamiLink.')
                : $this->cardReplaced($user, $event, $retired);
        }

        if (! $this->inArea($user, $event, $resident)) {
            // Nothing about the person is shown, so typing resident IDs cannot be used to learn who lives elsewhere.
            return $this->deny('outside_area', 'This code belongs to a resident of another barangay or municipality, so it cannot claim here.');
        }

        if ($resident->is_deceased) {
            return $this->deny('deceased', "{$resident->full_name} is recorded as deceased.", $resident);
        }

        if ($resident->status !== ResidentStatus::Approved) {
            return $this->deny('not_approved', $this->notApprovedMessage($resident), $resident);
        }

        if ($method === ReliefLog::MANUAL && $resident->hasMisplacedCard()) {
            return $this->deny('manual_blocked', "{$resident->full_name} reported a lost or stolen card, so the ID cannot be typed. Scan the new QR card.", $resident);
        }

        return $this->claim($user, $event, $resident, $method, $representative);
    }

    /**
     * A QR code that was replaced. The try is counted, and the encoder is told whose card it was only when the
     * resident belongs to the area they work in.
     *
     * @return ScanResult
     */
    private function cardReplaced(User $user, ReliefEvent $event, RetiredQrCode $retired): array
    {
        $retired->forceFill(['attempts' => $retired->attempts + 1, 'last_attempt_at' => now()])->save();

        $resident = $retired->resident;

        if (! $this->inArea($user, $event, $resident)) {
            return $this->deny('card_replaced', 'This QR card is no longer valid.');
        }

        return $this->deny(
            'card_replaced',
            "This QR card was reported {$retired->reason->value} and replaced on {$retired->created_at?->format('M j, Y')}. Ask {$resident->full_name} for the new card.",
            $resident,
        );
    }

    private function retired(string $code): ?RetiredQrCode
    {
        return $code === '' ? null : RetiredQrCode::query()->with(['resident.barangay', 'resident.household'])->where('qr_code_hash', $code)->first();
    }

    /**
     * Record the claim in a transaction. The household row is locked so two scanners cannot both pass the
     * household check, and the unique index on event and resident backs up the per-person check.
     *
     * @param  Representative|null  $representative
     * @return ScanResult
     */
    private function claim(User $user, ReliefEvent $event, Resident $resident, string $method, ?array $representative): array
    {
        try {
            return DB::transaction(function () use ($user, $event, $resident, $method, $representative): array {
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

                $earlier = $representative === null ? 0 : $this->earlierClaimsBy($event, $representative['name']);

                ReliefLog::query()->create([
                    'event_id' => $event->id,
                    'resident_id' => $resident->id,
                    'household_id' => $resident->household_id,
                    'scanned_by_user_id' => $user->id,
                    'status' => 'Claimed',
                    'entry_method' => $method,
                    'claimed_by_representative' => $representative !== null,
                    'representative_name' => $representative['name'] ?? null,
                    'representative_relationship' => $representative['relationship'] ?? null,
                    'representative_verified' => $representative['verified'] ?? false,
                ]);

                $result = $this->result(
                    true,
                    'granted',
                    $representative === null
                        ? "{$resident->full_name} may receive relief."
                        : "{$resident->full_name} may receive relief. It is collected by {$representative['name']} ({$representative['relationship']}).",
                    $resident,
                );

                if ($representative !== null && $earlier >= self::REPRESENTATIVE_WARNING_AFTER) {
                    $result['warning'] = "{$representative['name']} has already collected for {$earlier} other residents in this event. Check their authorization carefully.";
                }

                return $result;
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
            'collected_by' => $existing->claimed_by_representative ? $existing->representative_name : null,
        ];

        return $result;
    }

    /**
     * How many claims this event already has from the same representative, ignoring capitals and spacing.
     */
    private function earlierClaimsBy(ReliefEvent $event, string $name): int
    {
        return ReliefLog::query()
            ->where('event_id', $event->id)
            ->where('claimed_by_representative', true)
            ->whereRaw('lower(trim(representative_name)) = ?', [mb_strtolower(trim($name))])
            ->count();
    }

    /**
     * The resident behind a QR code, or behind a typed resident ID for a damaged code, and which of the two was used.
     *
     * @return array{0: Resident|null, 1: string}
     */
    private function find(string $code): array
    {
        if ($code === '') {
            return [null, ReliefLog::QR];
        }

        $byQr = Resident::query()->with(['barangay', 'household'])->where('qr_code_hash', $code)->first();

        if ($byQr !== null) {
            return [$byQr, ReliefLog::QR];
        }

        return [Resident::query()->with(['barangay', 'household'])->where('system_id_no', strtoupper($code))->first(), ReliefLog::MANUAL];
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
            'warning' => null,
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
