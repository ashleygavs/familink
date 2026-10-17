<?php

namespace App\Models;

use Database\Factories\ResidentChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change an admin made to a resident after approval, such as a marriage or a move to another household.
 * Kept so it is always clear who changed what and when.
 *
 * @property int $id
 * @property int $resident_id
 * @property int $changed_by_user_id
 * @property string $type
 * @property string $summary
 * @property array<string, mixed>|null $data
 * @property Carbon|null $created_at
 */
#[Fillable(['resident_id', 'changed_by_user_id', 'type', 'summary', 'data'])]
class ResidentChange extends Model
{
    /** @use HasFactory<ResidentChangeFactory> */
    use HasFactory;

    public const MARRIAGE = 'marriage';

    public const HOUSEHOLD_MOVE = 'household_move';

    public const DECEASED = 'deceased';

    public const LIVING = 'living';

    public const WIDOWED = 'widowed';

    public const MARRIAGE_ENDED = 'marriage_ended';

    public const CARD_REPLACED = 'card_replaced';

    public const CORRECTION = 'correction';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
