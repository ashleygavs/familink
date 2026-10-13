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
                'can_edit' => $actor->can('update', $user),
                'can_delete' => $actor->can('delete', $user),
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

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $user->delete();

        return back()->with('success', "Account deleted for {$user->name}.");
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
                'name' => $data['name'],
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
