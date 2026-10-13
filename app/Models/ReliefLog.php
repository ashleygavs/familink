<?php

namespace App\Models;

use Database\Factories\ReliefLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $event_id
 * @property int $resident_id
 * @property int $household_id
 * @property int $scanned_by_user_id
 * @property string $status
 * @property string|null $remarks
 */
#[Fillable(['event_id', 'resident_id', 'household_id', 'scanned_by_user_id', 'status', 'remarks'])]
class ReliefLog extends Model
{
    /** @use HasFactory<ReliefLogFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ReliefEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ReliefEvent::class, 'event_id');
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by_user_id');
    }
}
