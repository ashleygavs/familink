<?php

namespace App\Models;

use App\Enums\RelativeKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A relative a resident named when registering, such as "Marcus is my cousin".
 *
 * @property int $id
 * @property int $resident_id
 * @property int $relative_id
 * @property RelativeKind $relation
 */
#[Fillable(['resident_id', 'relative_id', 'relation'])]
class RelativeLink extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['relation' => RelativeKind::class];
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function relative(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'relative_id');
    }
}
