<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\SaveUserRequest;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

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

        $users = User::query()
            ->with(['role', 'barangay', 'municipality', 'managedBarangays:id,name'])
            ->when($barangayIds !== null, fn ($query) => $query
                ->whereHas('role', fn ($role) => $role->where('name', RoleName::User->value))
                ->whereIn('barangay_id', $barangayIds))
            ->orderBy('name')
            ->get()
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
                'can_delete' => $actor->can('delete', $user) && ! $user->hasHistory(),
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
        ]);
    }

    public function store(SaveUserRequest $request): RedirectResponse
    {
        $user = new User;
        $this->save($user, $request);

        return back()->with('success', "Account created for {$user->name}.");
    }

    public function update(SaveUserRequest $request, User $user): RedirectResponse
    {
        $this->save($user, $request);

        return back()->with('success', "Account updated for {$user->name}.");
    }

    public function deactivate(User $user): RedirectResponse
    {
        Gate::authorize('deactivate', $user);

        $lastSuperAdmin = $user->hasRole(RoleName::SuperAdmin)
            && ! User::query()->whereKeyNot($user->id)->whereNull('deactivated_at')
                ->whereHas('role', fn ($role) => $role->where('name', RoleName::SuperAdmin->value))->exists();

        if ($lastSuperAdmin) {
            return back()->withErrors(['user' => 'This is the last active super admin, so it cannot be deactivated.']);
        }

        $user->forceFill(['deactivated_at' => Carbon::now()])->save();

        return back()->with('success', "{$user->name} was deactivated and can no longer sign in.");
    }

    public function reactivate(User $user): RedirectResponse
    {
        Gate::authorize('reactivate', $user);

        $user->forceFill(['deactivated_at' => null])->save();

        return back()->with('success', "{$user->name} can sign in again.");
    }

    /**
     * Deleting is only for accounts made by mistake. Anyone with approvals, scans, events or recorded
     * answers is deactivated instead, so the history keeps its author.
     */
    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        if ($user->hasHistory()) {
            return back()->withErrors(['user' => "{$user->name} has records, so deactivate the account instead of deleting it."]);
        }

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

        DB::transaction(function () use ($user, $data, $role): void {
            $user->forceFill([
                'name' => $this->staffName($role, $data) ?? $data['name'],
                'email' => $data['email'],
                'role_id' => Role::query()->where('name', $role->value)->value('id'),
                'barangay_id' => $role === RoleName::User ? $data['barangay_id'] : null,
                'municipality_id' => $role === RoleName::Admin ? $data['municipality_id'] : null,
            ]);

            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            if (! $user->exists) {
                $user->email_verified_at = Carbon::now();
            }

            $user->save();
            $user->managedBarangays()->sync($role === RoleName::Admin ? $data['managed_barangay_ids'] : []);
        });
    }
}
