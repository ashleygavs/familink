<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to a suggested relative: accepted, rejected, unsure or reclassified ("related another way").
 *
 * @property int $id
 * @property int $resident_id
 * @property int $candidate_id
 * @property string $role
 * @property string $decision
 * @property string|null $reclassified_as
 * @property int|null $score
 * @property string|null $confidence
 * @property list<string>|null $reasons
 * @property string $context
 * @property int|null $decided_by_user_id
 */
#[Fillable([
    'resident_id', 'candidate_id', 'role', 'decision', 'reclassified_as',
    'score', 'confidence', 'reasons', 'context', 'decided_by_user_id',
])]
class SuggestionFeedback extends Model
{
    protected $table = 'suggestion_feedback';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['reasons' => 'array'];
    }

    /**
     * The person the suggestion was made for.
     *
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * The resident who was suggested.
     *
     * @return BelongsTo<Resident, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'candidate_id');
    }
}
