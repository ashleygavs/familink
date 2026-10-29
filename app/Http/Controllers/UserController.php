<?php

namespace App\Http\Controllers;

use App\Actions\Relief\RecordUserChange;
use App\Enums\RoleName;
use App\Http\Requests\SaveUserRequest;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Role;
use App\Models\User;
use App\Models\UserChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @phpstan-type HistoryLine array{id: int, type: string, target_name: string, target_email: string, summary: string, changed_by: string, changed_at: string|null}
 */
class UserController extends Controller
{
    /**
     * Staff accounts. A super admin sees everyone; an admin only sees the encoders of the barangays they handle.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $actor = $request->user();
        $barangayIds = $actor->accessibleBarangayIds();

        $filters = $request->validate([
            'history_q' => ['nullable', 'string', 'max:100'],
            'history_type' => ['nullable', Rule::in(UserChange::TYPES)],
            'history_user' => ['nullable', 'integer'],
        ]);

        $accounts = User::query()
            ->with(['role', 'barangay', 'municipality', 'managedBarangays:id,name'])
            ->when($barangayIds !== null, fn ($query) => $query
                ->whereHas('role', fn ($role) => $role->where('name', RoleName::User->value))
                ->whereIn('barangay_id', $barangayIds))
            ->orderBy('name')
            ->get();

        // Which accounts have history is worked out once for the whole list, not account by account.
        $withHistory = User::idsWithHistory($accounts->modelKeys());

        $users = $accounts
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->name,
                'barangay_id' => $user->barangay_id,
                'barangay_name' => $user->barangay?->name,
                'municipality_id' => $user->municipality_id,
                'municipality_name' => $user->municipality?->name,
                'managed_barangay_ids' => $user->managedBarangays->pluck('id')->all(),
                'managed_barangay_names' => $user->managedBarangays->pluck('name')->all(),
                'active' => $user->isActive(),
                'can_edit' => $actor->can('update', $user),
                'can_toggle' => $actor->can('deactivate', $user) || (! $user->isActive() && $actor->can('reactivate', $user)),
                'can_delete' => $actor->can('delete', $user) && ! $withHistory->contains($user->id),
            ]);

        return Inertia::render('admin/users', [
            'users' => $users,
            'municipalities' => Municipality::query()
                ->with(['barangays' => fn ($query) => $query
                    ->when($barangayIds !== null, fn ($inner) => $inner->whereIn('id', $barangayIds))
                    ->orderBy('name')])
                ->orderBy('name')
                ->get()
                ->filter(fn (Municipality $municipality) => $barangayIds === null || $municipality->barangays->isNotEmpty())
                ->map(fn (Municipality $municipality): array => [
                    'id' => $municipality->id,
                    'name' => $municipality->name,
                    'code' => $municipality->code(),
                    'barangays' => $municipality->barangays->map(fn (Barangay $barangay): array => ['id' => $barangay->id, 'name' => $barangay->name])->all(),
                ])->values(),
            'assignableRoles' => $actor->hasRole(RoleName::SuperAdmin) ? RoleName::values() : [RoleName::User->value],
            'history' => $this->history($barangayIds, $filters),
            'historyFilters' => [
                'q' => $filters['history_q'] ?? '',
                'type' => $filters['history_type'] ?? null,
                'user' => isset($filters['history_user']) ? (int) $filters['history_user'] : null,
                'user_name' => isset($filters['history_user'])
                    ? User::query()->whereKey($filters['history_user'])->when($barangayIds !== null, fn ($query) => $query->whereIn('barangay_id', $barangayIds))->value('name')
                    : null,
            ],
            'historyTypes' => UserChange::TYPES,
        ]);
    }

    /**
     * The changes made to staff accounts, newest first, a page at a time. They can be narrowed to one kind of change,
     * to one account, or to a word in the account, the summary or the name of whoever made the change. An admin only
     * ever sees the changes of the encoders they handle.
     *
     * @param  list<int>|null  $barangayIds
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, HistoryLine>
     */
    private function history(?array $barangayIds, array $filters): LengthAwarePaginator
    {
        $term = trim((string) ($filters['history_q'] ?? ''));
        $like = '%'.addcslashes($term, '%_\\').'%';

        return UserChange::query()
            ->with('changedBy')
            ->when($barangayIds !== null, fn ($query) => $query->whereIn('barangay_id', $barangayIds))
            ->when($filters['history_type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($filters['history_user'] ?? null, fn ($query, int|string $id) => $query->where('user_id', (int) $id))
            ->when($term !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('target_name', 'like', $like)
                ->orWhere('target_email', 'like', $like)
                ->orWhere('summary', 'like', $like)
                ->orWhereHas('changedBy', fn ($by) => $by->where('name', 'like', $like))))
            ->latest('id')
            ->paginate(15, pageName: 'history_page')
            ->withQueryString()
            ->through(fn (UserChange $change): array => [
                'id' => $change->id,
                'type' => $change->type,
                'target_name' => $change->target_name,
                'target_email' => $change->target_email,
                'summary' => $change->summary,
                'changed_by' => $change->changedBy->name,
                'changed_at' => $change->created_at?->toIso8601String(),
            ]);
    }

    public function store(SaveUserRequest $request, RecordUserChange $record): RedirectResponse
    {
        $user = new User;

        DB::transaction(function () use ($user, $request, $record): void {
            $this->save($user, $request);
            $record->created($request->user(), $user);
        });

        return back()->with('success', "Account created for {$user->name}.");
    }

    public function update(SaveUserRequest $request, User $user, RecordUserChange $record): RedirectResponse
    {
        DB::transaction(function () use ($user, $request, $record): void {
            $before = $record->snapshot($user);

            $this->save($user, $request);
            $record->updated($request->user(), $user, $before, ! empty($request->validated('password')));
        });

        return back()->with('success', "Account updated for {$user->name}.");
    }

    public function deactivate(Request $request, User $user, RecordUserChange $record): RedirectResponse
    {
        Gate::authorize('deactivate', $user);

        $lastSuperAdmin = $user->hasRole(RoleName::SuperAdmin)
            && ! User::query()->whereKeyNot($user->id)->whereNull('deactivated_at')
                ->whereHas('role', fn ($role) => $role->where('name', RoleName::SuperAdmin->value))->exists();

        if ($lastSuperAdmin) {
            return back()->withErrors(['user' => 'This is the last active super admin, so it cannot be deactivated.']);
        }

        $user->forceFill(['deactivated_at' => Carbon::now()])->save();
        $record->deactivated($request->user(), $user);

        return back()->with('success', "{$user->name} was deactivated and can no longer sign in.");
    }

    public function reactivate(Request $request, User $user, RecordUserChange $record): RedirectResponse
    {
        Gate::authorize('reactivate', $user);

        $user->forceFill(['deactivated_at' => null])->save();
        $record->reactivated($request->user(), $user);

        return back()->with('success', "{$user->name} can sign in again.");
    }

    /**
     * Deleting is only for accounts made by mistake. Anyone with approvals, scans, events or recorded
     * answers is deactivated instead, so the history keeps its author.
     */
    public function destroy(Request $request, User $user, RecordUserChange $record): RedirectResponse
    {
        Gate::authorize('delete', $user);

        if ($user->hasHistory()) {
            return back()->withErrors(['user' => "{$user->name} has records, so deactivate the account instead of deleting it."]);
        }

        $record->deleted($request->user(), $user);
        $user->delete();

        return back()->with('success', "Account deleted for {$user->name}.");
    }

    /**
     * The name of an admin or encoder, built from where they work: "BAC_San Jose Admin". Other roles are named by
     * hand, so this returns null for them.
     *
     * @param  array<string, mixed>  $data
     */
    private function staffName(RoleName $role, array $data): ?string
    {
        if ($role === RoleName::User) {
            $barangay = Barangay::query()->with('municipality')->find((int) $data['barangay_id']);

            return $barangay?->municipality === null ? null : "{$barangay->municipality->code()}_{$barangay->name} Encoder";
        }

        if ($role === RoleName::Admin) {
            $municipality = Municipality::query()->find((int) $data['municipality_id']);
            $barangays = Barangay::query()->whereKey($data['managed_barangay_ids'])->orderBy('name')->pluck('name');

            return $municipality === null || $barangays->isEmpty() ? null : "{$municipality->code()}_{$barangays->implode(' + ')} Admin";
        }

        return null;
    }

    /**
     * Save the account with the place it works in: an admin gets a municipality and the barangays they
     * handle, an encoder gets exactly one barangay.
     */
    private function save(User $user, SaveUserRequest $request): void
    {
        $data = $request->validated();
        $role = RoleName::from($data['role']);

        $actor = $request->user();

        DB::transaction(function () use ($user, $data, $role, $actor): void {
            $user->forceFill([
                'name' => $this->staffName($role, $data) ?? $data['name'],
                'email' => $data['email'],
                'role_id' => Role::query()->where('name', $role->value)->value('id'),
                'barangay_id' => $role === RoleName::User ? $data['barangay_id'] : null,
                'municipality_id' => $role === RoleName::Admin ? $data['municipality_id'] : null,
            ]);

            if (! empty($data['password'])) {
                $user->password = $data['password'];

                // A password someone else chose is only for the first login: the person picks their own after it.
                if ($actor->isNot($user)) {
                    $user->must_change_password = true;
                }
            }

            // The person saving the account vouches for the email, since no mail is sent to confirm it. This also
            // frees an account that was left unverified.
            if ($user->email_verified_at === null) {
                $user->email_verified_at = Carbon::now();
            }

            $user->save();
            $user->managedBarangays()->sync($role === RoleName::Admin ? $data['managed_barangay_ids'] : []);
        });
    }
}
