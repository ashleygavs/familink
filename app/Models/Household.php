<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $household_code
 * @property int $barangay_id
 * @property int|null $head_resident_id
 * @property string $street_address
 * @property-read int|null $residents_count
 */
#[Fillable(['household_code', 'barangay_id', 'head_resident_id', 'street_address'])]
class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Barangay, $this>
     */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /**
     * Give the head's place to someone who stays, or to no one when the household is left without a head.
     */
    public function passHeadTo(?Resident $successor): void
    {
        if ($this->head_resident_id !== null && $this->head_resident_id !== $successor?->id) {
            Resident::query()->whereKey($this->head_resident_id)->update(['household_role' => HouseholdRole::Other->value]);
        }

        $this->update(['head_resident_id' => $successor?->id]);
        $successor?->update(['household_role' => HouseholdRole::Head->value]);
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'head_resident_id');
    }

    /**
     * @return HasMany<Resident, $this>
     */
    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class);
    }

    /**
     * Relief claimed by members of this household, in every event.
     *
     * @return HasMany<ReliefLog, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(ReliefLog::class);
    }
}
