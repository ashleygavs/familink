<?php

namespace App\Models;

use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
