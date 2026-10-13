<?php

namespace App\Actions\Residents;

use App\Models\Household;
use App\Models\Resident;
use Illuminate\Support\Facades\DB;

class LinkRelatives
{
    public function __construct(private AdoptDependents $adoptDependents) {}

    /**
     * Fill the empty family slots of a resident (father, mother, spouse) and adopt children.
     * Slots that already hold a link are left untouched.
     *
     * @param  array<string, mixed>  $links  Any of father_id, mother_id, spouse_id, dependent_ids.
     */
    public function handle(Resident $resident, array $links): void
    {
        DB::transaction(function () use ($resident, $links): void {
            $updates = [];

            foreach (['father_id', 'mother_id', 'spouse_id'] as $slot) {
                if (! empty($links[$slot]) && $resident->{$slot} === null) {
                    $updates[$slot] = (int) $links[$slot];
                }
            }

            if ($updates !== []) {
                $resident->update($updates);
            }

            if (isset($updates['spouse_id'])) {
                Resident::query()->whereKey($updates['spouse_id'])->whereNull('spouse_id')->update(['spouse_id' => $resident->id]);
            }

            $dependentIds = array_values((array) ($links['dependent_ids'] ?? []));

            if ($dependentIds !== [] && $resident->household_id !== null) {
                $this->adoptDependents->handle($resident, Household::query()->findOrFail($resident->household_id), $dependentIds);
            }
        });
    }
}
