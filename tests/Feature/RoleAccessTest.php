<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\User;

dataset('role_routes', [
    'encoder register' => [RoleName::User, '/encoder/register'],
    'encoder scanner' => [RoleName::User, '/encoder/scanner'],
    'admin approvals' => [RoleName::Admin, '/admin/approvals'],
    'admin events' => [RoleName::Admin, '/admin/events'],
    'admin users' => [RoleName::Admin, '/admin/users'],
    'super admin municipalities' => [RoleName::SuperAdmin, '/admin/municipalities'],
]);

it('lets the matching role open its pages', function (RoleName $role, string $path) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)->get($path)->assertOk();
})->with('role_routes');

it('forbids other roles, except super admin, from opening a role-restricted page', function (RoleName $allowed, string $path) {
    foreach (RoleName::cases() as $role) {
        if ($role === $allowed || $role === RoleName::SuperAdmin) {
            continue;
        }

        $user = User::factory()->withRole($role)->create();

        $this->actingAs($user)->get($path)->assertForbidden();
    }
})->with('role_routes');

it('lets the super admin open every role-restricted page', function (RoleName $allowed, string $path) {
    $user = User::factory()->withRole(RoleName::SuperAdmin)->create();

    $this->actingAs($user)->get($path)->assertOk();
})->with('role_routes');

it('forbids users without a role from restricted pages', function () {
    $this->actingAs(User::factory()->create())->get('/admin/users')->assertForbidden();
});

it('limits the family tree to stakeholders, admins of that barangay and super admins', function () {
    $household = Household::factory()->create();
    $foreign = Household::factory()->create(['barangay_id' => Barangay::factory()]);
    $admin = User::factory()->withRole(RoleName::Admin)->create();

    foreach ([User::factory()->withRole(RoleName::Stakeholder)->create(), User::factory()->withRole(RoleName::SuperAdmin)->create(), $admin] as $user) {
        $this->actingAs($user)->get("/family-tree/{$household->id}")->assertOk();
    }

    $this->actingAs($admin)->get("/family-tree/{$foreign->id}")->assertForbidden();
    $this->actingAs(User::factory()->withRole(RoleName::User)->create())->get("/family-tree/{$household->id}")->assertForbidden();
});
it('redirects guests to the login page', function () {
    $this->get('/admin/users')->assertRedirect(route('login'));
});

it('redirects each role to its home page after login', function (RoleName $role, string $home) {
    $user = User::factory()->withRole($role)->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect($home);
})->with([
    'user' => [RoleName::User, '/encoder/register'],
    'admin' => [RoleName::Admin, '/admin/approvals'],
    'stakeholder' => [RoleName::Stakeholder, '/reports/analytics'],
    'super admin' => [RoleName::SuperAdmin, '/admin/users'],
]);

it('shares role, barangay and flash data with the frontend', function () {
    $user = User::factory()->withRole(RoleName::User)->create();

    $this->actingAs($user)
        ->withSession(['status' => 'Saved'])
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('auth.role_name', 'user')
            ->where('auth.barangay_id', $user->barangay_id)
            ->where('flash.status', 'Saved'));
});

it('does not expose a public registration route', function () {
    $this->get('/register')->assertNotFound();
});
