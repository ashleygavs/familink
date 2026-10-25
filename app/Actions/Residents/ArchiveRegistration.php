<?php

namespace App\Actions\Residents;

use App\Enums\ResidentStatus;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns a registration down. It is kept, not deleted, with who archived it and why, and the family links other
 * people's records had to this person are released so nobody is left married to, or the child of, an archived record.
 */
class ArchiveRegistration
{
    public function __construct(private RecordResidentChange $record, private UnlinkRelative $unlink) {}

    public function handle(User $actor, Resident $resident, string $reason): void
    {
        DB::transaction(function () use ($actor, $resident, $reason): void {
            $this->releaseLinks($actor, $resident);

            $resident->update(['status' => ResidentStatus::Archived, 'approved_by_user_id' => null]);

            $this->record->handle($resident, $actor, ResidentChange::ARCHIVED, "Registration archived by {$actor->name}: {$reason}", ['reason' => $reason]);
        });
    }

    /**
     * Cut the spouse link and the links of children that point at this person.
     */
    private function releaseLinks(User $actor, Resident $resident): void
    {
        if ($resident->spouse_id !== null) {
            $this->unlink->handle($resident, 'spouse', null, $actor);
        }

        $children = Resident::query()
            ->where(fn ($query) => $query->where('father_id', $resident->id)->orWhere('mother_id', $resident->id))
            ->pluck('id');

        foreach ($children as $childId) {
            $this->unlink->handle($resident, 'child', (int) $childId, $actor);
        }
    }
}
