<?php

namespace App\Actions\Residents;

use App\Enums\ResidentStatus;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Approves a registration. The approver is kept on the resident and in their history, so it is always clear who let
 * someone receive relief.
 */
class ApproveRegistration
{
    public function __construct(private RecordResidentChange $record) {}

    public function handle(User $actor, Resident $resident): void
    {
        DB::transaction(function () use ($actor, $resident): void {
            $resident->update(['status' => ResidentStatus::Approved, 'approved_by_user_id' => $actor->id]);

            $this->record->handle($resident, $actor, ResidentChange::APPROVED, "Approved by {$actor->name}.");
        });
    }
}
