<?php

namespace App\Models;

use App\Enums\AllocationType;
use Database\Factories\ReliefEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property AllocationType $allocation_type
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $status
 * @property int $created_by_user_id
 */
#[Fillable(['title', 'allocation_type', 'start_date', 'end_date', 'status', 'created_by_user_id'])]
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
            'start_date' => 'date',
            'end_date' => 'date',
        ];
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
