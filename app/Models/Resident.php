<?php

namespace App\Models;

use App\Enums\ResidentStatus;
use Database\Factories\ResidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'system_id_no', 'qr_code_hash', 'household_id', 'barangay_id',
    'first_name', 'middle_name', 'last_name', 'birthdate', 'gender', 'civil_status', 'household_role',
    'spouse_id', 'father_id', 'mother_id', 'status', 'approved_by_user_id',
])]
class Resident extends Model
{
    /** @use HasFactory<ResidentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'status' => ResidentStatus::class,
        ];
    }

    public function getFullNameAttribute(): string
    {
        return collect([$this->first_name, $this->middle_name, $this->last_name])->filter()->implode(' ');
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

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
    public function father(): BelongsTo
    {
        return $this->belongsTo(self::class, 'father_id');
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function mother(): BelongsTo
    {
        return $this->belongsTo(self::class, 'mother_id');
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function spouse(): BelongsTo
    {
        return $this->belongsTo(self::class, 'spouse_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return HasMany<Resident, $this>
     */
    public function childrenAsFather(): HasMany
    {
        return $this->hasMany(self::class, 'father_id');
    }

    /**
     * @return HasMany<Resident, $this>
     */
    public function childrenAsMother(): HasMany
    {
        return $this->hasMany(self::class, 'mother_id');
    }
}
