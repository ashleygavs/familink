<?php

namespace App\Models;

use Database\Factories\ReliefEventChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to a relief event: who created it, edited it, moved it to another status or deleted it, and what
 * changed. Kept so it is always clear who set the rules of a distribution, including its limit per household.
 *
 * @property int $id
 * @property int|null $relief_event_id
 * @property int|null $municipality_id
 * @property string $event_title
 * @property int $changed_by_user_id
 * @property string $type
 * @property string $summary
 * @property array<string, mixed>|null $data
 * @property Carbon|null $created_at
 */
#[Fillable(['relief_event_id', 'municipality_id', 'event_title', 'changed_by_user_id', 'type', 'summary', 'data'])]
class ReliefEventChange extends Model
{
    /** @use HasFactory<ReliefEventChangeFactory> */
    use HasFactory;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    /**
     * @return BelongsTo<ReliefEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ReliefEvent::class, 'relief_event_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
