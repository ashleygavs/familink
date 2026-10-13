<?php

namespace App\Models;

use App\Enums\AllocationType;
use App\Enums\EventStatus;
use Database\Factories\ReliefEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property int|null $municipality_id
 * @property AllocationType $allocation_type
 * @property int|null $max_claims_per_household
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property EventStatus $status
 * @property int $created_by_user_id
 */
#[Fillable(['title', 'municipality_id', 'allocation_type', 'max_claims_per_household', 'start_date', 'end_date', 'status', 'created_by_user_id'])]
class ReliefEvent extends Model
{
    /** @use HasFactory<ReliefEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allocation_type' => AllocationType::class,
            'max_claims_per_household' => 'integer',
            'status' => EventStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * The most claims one household may make, or null for no limit. A Per Household event is always one.
     */
    public function claimLimitPerHousehold(): ?int
    {
        return $this->allocation_type === AllocationType::PerHousehold ? 1 : $this->max_claims_per_household;
    }

    /**
     * Whether claims can be scanned right now: the event is Active and today is inside its dates.
     */
    public function isOpenForScanning(): bool
    {
        $today = Carbon::today();

        return $this->status === EventStatus::Active
            && $today->greaterThanOrEqualTo($this->start_date)
            && $today->lessThanOrEqualTo($this->end_date);
    }

    /**
     * Only the events of municipalities the user works in.
     *
     * @param  Builder<ReliefEvent>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $ids = $user->accessibleMunicipalityIds();

        if ($ids !== null) {
            $query->whereIn('municipality_id', $ids);
        }
    }

    /**
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<ReliefLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ReliefLog::class, 'event_id');
    }
}
