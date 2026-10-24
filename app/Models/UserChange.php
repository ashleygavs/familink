<?php

namespace App\Models;

use Database\Factories\UserChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to a staff account: who created it, edited it, deactivated or reactivated it, or deleted it, and what
 * changed. Kept so it is always clear who gave someone access and who took it away. Passwords are never recorded,
 * only that one was set.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $barangay_id
 * @property string $target_name
 * @property string $target_email
 * @property int $changed_by_user_id
 * @property string $type
 * @property string $summary
 * @property array<string, mixed>|null $data
 * @property Carbon|null $created_at
 */
#[Fillable(['user_id', 'barangay_id', 'target_name', 'target_email', 'changed_by_user_id', 'type', 'summary', 'data'])]
class UserChange extends Model
{
    /** @use HasFactory<UserChangeFactory> */
    use HasFactory;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DEACTIVATED = 'deactivated';

    public const REACTIVATED = 'reactivated';

    public const DELETED = 'deleted';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
