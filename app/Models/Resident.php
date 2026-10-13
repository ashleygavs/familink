<?php

namespace App\Models;

use App\Enums\ResidentStatus;
use Database\Factories\ResidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $system_id_no
 * @property string $qr_code_hash
 * @property int|null $household_id
 * @property int|null $barangay_id
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property string|null $suffix
 * @property string|null $maiden_name
 * @property Carbon $birthdate
 * @property bool $birthdate_estimated
 * @property string $gender
 * @property string|null $civil_status
 * @property string|null $household_role
 * @property int|null $spouse_id
 * @property int|null $father_id
 * @property int|null $mother_id
 * @property ResidentStatus $status
 * @property bool $is_deceased
 * @property int|null $approved_by_user_id
 * @property int|null $listed_by_resident_id
 * @property string|null $first_name_key
 * @property string|null $middle_name_key
 * @property string|null $last_name_key
 * @property string|null $maiden_name_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $full_name
 */
#[Fillable([
    'system_id_no', 'qr_code_hash', 'household_id', 'barangay_id',
    'first_name', 'middle_name', 'last_name', 'suffix', 'maiden_name', 'birthdate', 'birthdate_estimated',
    'gender', 'civil_status', 'household_role',
    'spouse_id', 'father_id', 'mother_id', 'status', 'is_deceased',
    'approved_by_user_id', 'listed_by_resident_id',
])]
class Resident extends Model
{
    /** @use HasFactory<ResidentFactory> */
    use HasFactory;

    /**
     * Keep the normalised name keys (used to match relatives) in sync with the names.
     */
    protected static function booted(): void
    {
        static::saving(function (Resident $resident): void {
            $resident->first_name_key = self::normalizeName($resident->first_name);
            $resident->middle_name_key = self::normalizeName($resident->middle_name);
            $resident->last_name_key = self::normalizeName($resident->last_name);
            $resident->maiden_name_key = self::normalizeName($resident->maiden_name);
        });
    }

    /**
     * Reduce a name to lowercase letters only, so "Dela Cruz", "De la Cruz" and "DELACRUZ" match.
     */
    public static function normalizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $key = preg_replace('/[^a-z]/', '', Str::lower(Str::ascii($name)));

        return $key === '' || $key === null ? null : $key;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'birthdate_estimated' => 'boolean',
            'is_deceased' => 'boolean',
            'status' => ResidentStatus::class,
        ];
    }

    public function getFullNameAttribute(): string
    {
        return collect([$this->first_name, $this->middle_name, $this->last_name, $this->suffix])->filter()->implode(' ');
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
     * Only the residents in barangays the user may work with. A listed resident with no barangay yet
     * belongs to the barangay of the resident who listed them.
     *
     * @param  Builder<Resident>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $ids = $user->accessibleBarangayIds();

        if ($ids === null) {
            return;
        }

        $query->where(fn (Builder $scoped) => $scoped
            ->whereIn('barangay_id', $ids)
            ->orWhereHas('listedBy', fn (Builder $lister) => $lister->whereIn('barangay_id', $ids)));
    }

    /**
     * The resident who declared this person as a relative before they registered.
     *
     * @return BelongsTo<Resident, $this>
     */
    public function listedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'listed_by_resident_id');
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
