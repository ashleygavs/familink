<?php

namespace App\Actions\Residents;

use App\Models\RelativeLink;
use App\Models\Resident;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UnlinkRelative
{
    /**
     * Remove one family link from a resident: the father, mother or spouse, a child, or a named relative.
     * The other person's record is kept; only the connection is cut.
     *
     * @param  string  $kind  father, mother, spouse, child or relative.
     * @param  int|null  $id  The child's resident id, or the relative link id.
     */
    public function handle(Resident $resident, string $kind, ?int $id = null): void
    {
        DB::transaction(function () use ($resident, $kind, $id): void {
            match ($kind) {
                'father' => $resident->update(['father_id' => null]),
                'mother' => $resident->update(['mother_id' => null]),
                'spouse' => $this->unlinkSpouse($resident),
                'child' => $this->unlinkChild($resident, (int) $id),
                'relative' => RelativeLink::query()
                    ->whereKey($id)
                    ->where(fn ($query) => $query->where('resident_id', $resident->id)->orWhere('relative_id', $resident->id))
                    ->delete(),
                default => throw new InvalidArgumentException("Unknown family link: {$kind}"),
            };
        });
    }

    private function unlinkSpouse(Resident $resident): void
    {
        if ($resident->spouse_id === null) {
            return;
        }

        Resident::query()->whereKey($resident->spouse_id)->where('spouse_id', $resident->id)->update(['spouse_id' => null]);
        $resident->update(['spouse_id' => null]);
    }

    private function unlinkChild(Resident $resident, int $childId): void
    {
        Resident::query()->whereKey($childId)->where('father_id', $resident->id)->update(['father_id' => null]);
        Resident::query()->whereKey($childId)->where('mother_id', $resident->id)->update(['mother_id' => null]);
    }
}
