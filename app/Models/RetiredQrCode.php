<?php

namespace App\Models;

use App\Enums\CardReplacementReason;
use Database\Factories\RetiredQrCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A QR code that was replaced and no longer works. Scanning it is refused, and each try is counted.
 *
 * @property int $id
 * @property int $resident_id
 * @property string $qr_code_hash
 * @property CardReplacementReason $reason
 * @property int $replaced_by_user_id
 * @property int $attempts
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $created_at
 */
#[Fillable(['resident_id', 'qr_code_hash', 'reason', 'replaced_by_user_id', 'attempts', 'last_attempt_at'])]
class RetiredQrCode extends Model
{
    /** @use HasFactory<RetiredQrCodeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['reason' => CardReplacementReason::class, 'last_attempt_at' => 'datetime'];
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
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaced_by_user_id');
    }
}
