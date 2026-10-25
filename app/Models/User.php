<?php

namespace App\Models;

use App\Enums\RoleName;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $deactivated_at
 * @property string $password
 * @property int|null $role_id
 * @property int|null $barangay_id
 * @property int|null $municipality_id
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Barangay, $this>
     */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /**
     * The municipality an admin is assigned to.
     *
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * The barangays an admin handles, always inside their municipality.
     *
     * @return BelongsToMany<Barangay, $this>
     */
    public function managedBarangays(): BelongsToMany
    {
        return $this->belongsToMany(Barangay::class, 'admin_barangay')->withTimestamps();
    }

    /**
     * The barangays this user may work with: null means every barangay (super admin and stakeholder),
     * an admin gets the barangays they handle, an encoder gets their own barangay.
     *
     * @return list<int>|null
     */
    public function accessibleBarangayIds(): ?array
    {
        return once(fn (): ?array => $this->resolveAccessibleBarangayIds());
    }

    /**
     * @return list<int>|null
     */
    private function resolveAccessibleBarangayIds(): ?array
    {
        if ($this->hasRole(RoleName::SuperAdmin, RoleName::Stakeholder)) {
            return null;
        }

        if ($this->hasRole(RoleName::Admin)) {
            return array_values($this->managedBarangays()->pluck('barangays.id')->map(fn ($id): int => (int) $id)->all());
        }

        return $this->barangay_id === null ? [] : [$this->barangay_id];
    }

    /**
     * Where this user works, for display: "All Barangays", "Bacolor: San Jose, Cabalantian" for an admin,
     * or the encoder's own barangay.
     */
    public function scopeLabel(): string
    {
        if ($this->accessibleBarangayIds() === null) {
            return 'All Barangays';
        }

        if ($this->hasRole(RoleName::Admin)) {
            $names = $this->managedBarangays()->orderBy('name')->pluck('name');

            return trim(($this->municipality_id === null ? 'No municipality' : $this->municipality->name).': '.($names->isEmpty() ? 'no barangays yet' : $names->implode(', ')));
        }

        return $this->barangay?->name === null ? 'No barangay assigned' : 'Barangay '.$this->barangay->name;
    }

    /**
     * The municipalities this user works in, for relief events: null means all of them. An admin has their
     * assigned municipality and an encoder the municipality of their barangay.
     *
     * @return list<int>|null
     */
    public function accessibleMunicipalityIds(): ?array
    {
        if ($this->hasRole(RoleName::SuperAdmin, RoleName::Stakeholder)) {
            return null;
        }

        $id = $this->hasRole(RoleName::Admin) ? $this->municipality_id : $this->barangay?->municipality_id;

        return $id === null ? [] : [$id];
    }

    public function canAccessBarangay(?int $barangayId): bool
    {
        $ids = $this->accessibleBarangayIds();

        return $ids === null || ($barangayId !== null && in_array($barangayId, $ids, true));
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * Whether this account has approved, scanned, created events or answered suggestions, which a delete
     * would orphan. Such accounts are deactivated, never deleted.
     */
    public function hasHistory(): bool
    {
        return self::idsWithHistory([$this->id])->contains($this->id);
    }

    /**
     * Of these accounts, the ones that have history. It asks each table once for all of them, so a list of staff
     * costs the same few questions however long it is.
     *
     * @param  iterable<int>  $ids
     * @return Collection<int, int>
     */
    public static function idsWithHistory(iterable $ids): Collection
    {
        $ids = collect($ids)->unique()->values();

        return collect([
            [Resident::class, 'approved_by_user_id'],
            [ReliefLog::class, 'scanned_by_user_id'],
            [ReliefEvent::class, 'created_by_user_id'],
            [SuggestionFeedback::class, 'decided_by_user_id'],
            [ResidentChange::class, 'changed_by_user_id'],
            [ReliefEventChange::class, 'changed_by_user_id'],
            [UserChange::class, 'changed_by_user_id'],
        ])
            ->flatMap(fn (array $source): array => $source[0]::query()->whereIn($source[1], $ids)->distinct()->pluck($source[1])->all())
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    public function hasRole(RoleName|string ...$roles): bool
    {
        $current = $this->role?->name;

        return $current !== null && collect($roles)
            ->map(fn (RoleName|string $role) => $role instanceof RoleName ? $role->value : $role)
            ->contains($current);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
