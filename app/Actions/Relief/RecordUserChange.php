<?php

namespace App\Actions\Relief;

use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Role;
use App\Models\User;
use App\Models\UserChange;

/**
 * Writes the history of a staff account: its creation, each edit with the old and new values, being deactivated or
 * reactivated, and its deletion. A password is never written down, only that it was set.
 */
class RecordUserChange
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'name' => 'Name',
        'email' => 'Email',
        'role' => 'Role',
        'barangay' => 'Barangay',
        'municipality' => 'Municipality',
        'managed_barangays' => 'Barangays handled',
    ];

    /**
     * @var array<string, string>
     */
    private const ROLE_NAMES = [
        'super_admin' => 'Super admin',
        'admin' => 'Admin',
        'stakeholder' => 'Stakeholder',
        'user' => 'Encoder',
    ];

    public function created(User $actor, User $user): void
    {
        $this->write($actor, $user, UserChange::CREATED, 'Created the account ('.$this->describe($user).').', [
            'after' => $this->snapshot($user),
        ]);
    }

    /**
     * @param  array<string, string|null>  $before  The snapshot taken before the account was saved.
     */
    public function updated(User $actor, User $user, array $before, bool $passwordChanged): void
    {
        $after = $this->snapshot($user);
        $changed = array_keys(array_filter($after, fn (?string $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

        $parts = array_map(
            fn (string $field): string => self::LABELS[$field].' from '.($before[$field] ?? 'none').' to '.($after[$field] ?? 'none'),
            $changed,
        );

        if ($parts === [] && ! $passwordChanged) {
            return;
        }

        $summary = $parts === [] ? '' : 'Changed '.implode('; ', $parts).'.';
        $summary = trim($summary.($passwordChanged ? ($actor->is($user) ? ' A new password was set.' : ' A temporary password was set. The person must choose their own at the next login.') : ''));

        $this->write($actor, $user, UserChange::UPDATED, $summary, [
            'before' => array_intersect_key($before, array_flip($changed)),
            'after' => array_intersect_key($after, array_flip($changed)),
            'password_changed' => $passwordChanged,
        ]);
    }

    /**
     * A password reset by whoever runs the server. There is no signed-in person to credit, and the history needs one, so
     * the account itself is named, and the summary says where the reset came from.
     */
    public function resetFromServer(User $user): void
    {
        $this->write($user, $user, UserChange::UPDATED, 'Password reset from the server. A temporary password was set. The person must choose their own at the next login.', [
            'password_changed' => true,
            'from_server' => true,
        ]);
    }

    public function deactivated(User $actor, User $user): void
    {
        $this->write($actor, $user, UserChange::DEACTIVATED, 'Deactivated the account. It can no longer sign in.', []);
    }

    public function reactivated(User $actor, User $user): void
    {
        $this->write($actor, $user, UserChange::REACTIVATED, 'Reactivated the account. It can sign in again.', []);
    }

    public function deleted(User $actor, User $user): void
    {
        $this->write($actor, $user, UserChange::DELETED, 'Deleted the account ('.$this->describe($user).').', [
            'before' => $this->snapshot($user),
        ]);
    }

    /**
     * What is compared and recorded about an account, as plain text.
     *
     * @return array<string, string|null>
     */
    public function snapshot(User $user): array
    {
        $role = $user->role_id === null ? null : Role::query()->whereKey($user->role_id)->value('name');
        $managed = $user->exists ? $user->managedBarangays()->orderBy('name')->pluck('barangays.name')->implode(', ') : '';

        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role === null ? null : (self::ROLE_NAMES[$role] ?? $role),
            'barangay' => $user->barangay_id === null ? null : Barangay::query()->whereKey($user->barangay_id)->value('name'),
            'municipality' => $user->municipality_id === null ? null : Municipality::query()->whereKey($user->municipality_id)->value('name'),
            'managed_barangays' => $managed === '' ? null : $managed,
        ];
    }

    private function describe(User $user): string
    {
        $snapshot = $this->snapshot($user);
        $place = $snapshot['barangay'] ?? $snapshot['managed_barangays'] ?? $snapshot['municipality'];

        return ($snapshot['role'] ?? 'No role').($place === null ? '' : ", {$place}").", {$user->email}";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(User $actor, User $user, string $type, string $summary, array $data): void
    {
        UserChange::query()->create([
            'user_id' => $user->exists ? $user->id : null,
            'barangay_id' => $user->barangay_id,
            'target_name' => $user->name,
            'target_email' => $user->email,
            'changed_by_user_id' => $actor->id,
            'type' => $type,
            'summary' => $summary,
            'data' => $data === [] ? null : $data,
        ]);
    }
}
