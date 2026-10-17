<?php

namespace App\Actions\Residents;

use App\Enums\CardReplacementReason;
use App\Enums\ResidentStatus;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\RetiredQrCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives a resident a new QR code after their card was lost, stolen or damaged. The old code stops working at once
 * and is kept, so a scan of it is refused and counted. The resident ID does not change.
 */
class ReplaceQrCard
{
    public function __construct(private GenerateIdentifiers $identifiers) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, Resident $resident, CardReplacementReason $reason): void
    {
        if ($resident->status !== ResidentStatus::Approved || $resident->is_deceased) {
            throw ValidationException::withMessages(['reason' => 'Only an approved, living resident has a QR card to replace.']);
        }

        DB::transaction(function () use ($actor, $resident, $reason): void {
            RetiredQrCode::query()->create([
                'resident_id' => $resident->id,
                'qr_code_hash' => $resident->qr_code_hash,
                'reason' => $reason,
                'replaced_by_user_id' => $actor->id,
            ]);

            $resident->update(['qr_code_hash' => $this->identifiers->qrHash()]);

            ResidentChange::query()->create([
                'resident_id' => $resident->id,
                'changed_by_user_id' => $actor->id,
                'type' => ResidentChange::CARD_REPLACED,
                'summary' => "QR card replaced ({$reason->value}). The old code no longer works."
                    .($reason->isMisplaced() ? ' The resident ID can no longer be typed in place of the QR code.' : ''),
                'data' => ['reason' => $reason->value],
            ]);
        });
    }
}
