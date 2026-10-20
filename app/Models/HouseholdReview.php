<?php

namespace App\Models;

use Database\Factories\HouseholdReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An admin's note that two households were looked at together and are fine, for example because a couple really do
 * live apart. The pair then stays out of the list of households that may be splitting.
 *
 * @property int $id
 * @property int $first_household_id
 * @property int $second_household_id
 * @property string $reason
 * @property int $reviewed_by_user_id
 * @property Carbon|null $created_at
 */
#[Fillable(['first_household_id', 'second_household_id', 'reason', 'reviewed_by_user_id'])]
class HouseholdReview extends Model
{
    /** @use HasFactory<HouseholdReviewFactory> */
    use HasFactory;

    /**
     * The two household ids in the order they are stored: the lower one first.
     *
     * @return array{first_household_id: int, second_household_id: int}
     */
    public static function pair(int $one, int $other): array
    {
        return ['first_household_id' => min($one, $other), 'second_household_id' => max($one, $other)];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
