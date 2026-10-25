<?php

namespace App\Actions\Residents;

use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Writes one line of a resident's history: who did what, and when. The line is kept short enough to read at a glance,
 * and anything longer goes into the data kept beside it.
 */
class RecordResidentChange
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Resident|int $resident, User $actor, string $type, string $summary, array $data = []): ResidentChange
    {
        return ResidentChange::query()->create([
            'resident_id' => $resident instanceof Resident ? $resident->id : $resident,
            'changed_by_user_id' => $actor->id,
            'type' => $type,
            'summary' => Str::limit($summary, 237),
            'data' => $data === [] ? null : $data,
        ]);
    }
}
