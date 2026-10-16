<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Municipality;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $this->municipality = Municipality::factory()->create();
    $this->mine = Barangay::factory()->create(['municipality_id' => $this->municipality->id]);
    $this->second = Barangay::factory()->create(['municipality_id' => $this->municipality->id]);
    $this->other = Barangay::factory()->create();

    $this->superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create();
    $this->admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $this->municipality->id]);
    $this->admin->managedBarangays()->sync([$this->mine->id]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function staffPayload(array $overrides = []): array
{
    return [
        'name' => 'New Staff', 'email' => 'staff@example.test', 'password' => 'secret-pass-123',
        'role' => 'user', 'barangay_id' => test()->mine->id, ...$overrides,
    ];
}

it('lets a super admin create an admin for a municipality and several of its barangays', function () {
    $this->actingAs($this->superAdmin)->post(route('admin.users.store'), staffPayload([
        'role' => 'admin', 'barangay_id' => null, 'municipality_id' => $this->municipality->id,
        'managed_barangay_ids' => [$this->mine->id, $this->second->id],
    ]))->assertSessionHasNoErrors();

    $created = User::query()->where('email', 'staff@example.test')->firstOrFail();

    expect($created->hasRole(RoleName::Admin))->toBeTrue()
        ->and($created->municipality_id)->toBe($this->municipality->id)
        ->and($created->accessibleBarangayIds())->toEqualCanonicalizing([$this->mine->id, $this->second->id])
        ->and($created->email_verified_at)->not->toBeNull();
});

it('names admins and encoders from where they work and ignores a typed name', function () {
    $municipality = Municipality::factory()->create(['name' => 'Bacolor']);
    $sanJose = Barangay::factory()->create(['municipality_id' => $municipality->id, 'name' => 'San Jose']);
    $cabalantian = Barangay::factory()->create(['municipality_id' => $municipality->id, 'name' => 'Cabalantian']);

    $this->actingAs($this->superAdmin)->post(route('admin.users.store'), staffPayload([
        'name' => 'Typed Name', 'email' => 'enc@example.test', 'barangay_id' => $sanJose->id,
    ]))->assertSessionHasNoErrors();
    $this->actingAs($this->superAdmin)->post(route('admin.users.store'), staffPayload([
        'name' => '', 'email' => 'adm@example.test', 'role' => 'admin', 'barangay_id' => null,
        'municipality_id' => $municipality->id, 'managed_barangay_ids' => [$sanJose->id, $cabalantian->id],
    ]))->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'enc@example.test')->value('name'))->toBe('BAC_San Jose Encoder')
        ->and(User::query()->where('email', 'adm@example.test')->value('name'))->toBe('BAC_Cabalantian + San Jose Admin');
});

it('still needs a typed name for the super admin and stakeholder roles', function (string $role) {
    $this->actingAs($this->superAdmin)->post(route('admin.users.store'), staffPayload([
        'name' => '', 'role' => $role, 'barangay_id' => null,
    ]))->assertSessionHasErrors('name');

    $this->actingAs($this->superAdmin)->post(route('admin.users.store'), staffPayload([
        'name' => 'Audit Person', 'role' => $role, 'barangay_id' => null,
    ]))->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'staff@example.test')->value('name'))->toBe('Audit Person');
})->with(['super_admin', 'stakeholder']);

it('derives a municipality code from its name', function (string $name, string $code) {
    expect(Municipality::factory()->make(['name' => $name])->code())->toBe($code);
})->with([
    'one word' => ['Bacolor', 'BAC'],
    'two words' => ['San Luis', 'SLU'],
    'short word' => ['Ba', 'BA'],
    'three words' => ['Santa Rita Norte', 'SRN'],
]);

it('rejects an admin handling barangays outside their municipality', function () {
    $this->actingAs($this->superAdmin)->post(route('admin.users.store'), staffPayload([
        'role' => 'admin', 'barangay_id' => null, 'municipality_id' => $this->municipality->id,
        'managed_barangay_ids' => [$this->other->id],
    ]))->assertSessionHasErrors('managed_barangay_ids.0');
});

it('requires one barangay for an encoder', function () {
    $this->actingAs($this->superAdmin)->post(route('admin.users.store'), staffPayload(['barangay_id' => null]))
        ->assertSessionHasErrors('barangay_id');
});

it('lets an admin create an encoder in a barangay they handle', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), staffPayload())->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'staff@example.test')->value('barangay_id'))->toBe($this->mine->id);
});

it('stops an admin assigning encoders to barangays they do not handle', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), staffPayload(['barangay_id' => $this->second->id]))
        ->assertSessionHasErrors('barangay_id');
});

it('never lets an admin create or promote anyone to admin or super admin', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), staffPayload(['role' => 'super_admin']))->assertSessionHasErrors('role');
    $this->actingAs($this->admin)->post(route('admin.users.store'), staffPayload(['role' => 'admin', 'municipality_id' => $this->municipality->id, 'managed_barangay_ids' => [$this->mine->id]]))->assertSessionHasErrors('role');

    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id]);
    $this->actingAs($this->admin)->put(route('admin.users.update', $encoder), staffPayload(['email' => $encoder->email, 'role' => 'super_admin']))->assertSessionHasErrors('role');

    expect($encoder->fresh()->hasRole(RoleName::User))->toBeTrue();
});

it('stops an admin editing admins, super admins or encoders of other barangays', function () {
    $foreignEncoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->other->id]);

    foreach ([$this->superAdmin, $this->admin, $foreignEncoder] as $target) {
        $this->actingAs($this->admin)->put(route('admin.users.update', $target), staffPayload(['email' => $target->email]))->assertForbidden();
    }
});

it('lets an admin move their encoder between barangays they handle but not delete accounts', function () {
    $this->admin->managedBarangays()->sync([$this->mine->id, $this->second->id]);
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id]);

    $this->actingAs($this->admin)->put(route('admin.users.update', $encoder), staffPayload(['email' => $encoder->email, 'password' => '', 'barangay_id' => $this->second->id]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->delete(route('admin.users.destroy', $encoder))->assertForbidden();

    expect($encoder->fresh()->barangay_id)->toBe($this->second->id);
});

it('lists only the encoders an admin handles', function () {
    User::factory()->withRole(RoleName::User)->create(['name' => 'Mine', 'barangay_id' => $this->mine->id]);
    User::factory()->withRole(RoleName::User)->create(['name' => 'Foreign', 'barangay_id' => $this->other->id]);

    $this->actingAs($this->admin)->get(route('admin.users'))
        ->assertInertia(fn ($page) => $page->has('users', 1)->where('users.0.name', 'Mine')->where('assignableRoles', ['user']));
});

it('lets a super admin delete other accounts but never their own', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id]);

    $this->actingAs($this->superAdmin)->delete(route('admin.users.destroy', $this->superAdmin))->assertForbidden();
    $this->actingAs($this->superAdmin)->delete(route('admin.users.destroy', $encoder))->assertRedirect();

    expect(User::query()->find($encoder->id))->toBeNull();
});

it('keeps a super admin from changing their own role', function () {
    $this->actingAs($this->superAdmin)->put(route('admin.users.update', $this->superAdmin), staffPayload(['email' => $this->superAdmin->email, 'role' => 'user']))
        ->assertSessionHasErrors('role');
});

it('keeps encoders and stakeholders out of staff management', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id]);
    $stakeholder = User::factory()->withRole(RoleName::Stakeholder)->create();

    foreach ([$encoder, $stakeholder] as $user) {
        $this->actingAs($user)->get(route('admin.users'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.municipalities'))->assertForbidden();
    }
});

it('lets only a super admin manage municipalities and barangays', function () {
    $this->actingAs($this->admin)->get(route('admin.municipalities'))->assertForbidden();
    $this->actingAs($this->admin)->post(route('admin.barangays.store'), ['municipality_id' => $this->municipality->id, 'name' => 'Nope', 'code' => 'NOP'])->assertForbidden();

    $this->actingAs($this->superAdmin)->post(route('admin.municipalities.store'), ['name' => 'Lubao', 'province' => 'Pampanga'])->assertSessionHasNoErrors();
    $lubao = Municipality::query()->where('name', 'Lubao')->firstOrFail();
    $this->actingAs($this->superAdmin)->post(route('admin.barangays.store'), ['municipality_id' => $lubao->id, 'name' => 'Santa Cruz', 'code' => 'SC'])->assertSessionHasNoErrors();

    expect($lubao->barangays()->count())->toBe(1);
});

it('refuses to delete a municipality or barangay that is still in use', function () {
    Resident::factory()->create(['barangay_id' => $this->mine->id, 'household_id' => Household::factory()->create(['barangay_id' => $this->mine->id])->id]);

    $this->actingAs($this->superAdmin)->delete(route('admin.barangays.destroy', $this->mine))->assertSessionHasErrors('barangay');
    $this->actingAs($this->superAdmin)->delete(route('admin.municipalities.destroy', $this->municipality))->assertSessionHasErrors('municipality');

    $empty = Barangay::factory()->create();
    $this->actingAs($this->superAdmin)->delete(route('admin.barangays.destroy', $empty))->assertRedirect();

    expect(Barangay::query()->find($empty->id))->toBeNull()->and(Barangay::query()->find($this->mine->id))->not->toBeNull();
});

it('only shows an admin the residents of the barangays they handle', function () {
    $inside = Resident::factory()->pending()->create(['barangay_id' => $this->mine->id]);
    $outside = Resident::factory()->pending()->create(['barangay_id' => $this->other->id]);

    $this->actingAs($this->admin)->get(route('admin.approvals'))
        ->assertInertia(fn ($page) => $page->has('pending.data', 1)->where('pending.data.0.id', $inside->id));

    $this->actingAs($this->admin)->post(route('admin.approvals.approve', $outside))->assertForbidden();
    $this->actingAs($this->admin)->post(route('admin.approvals.approve', $inside))->assertRedirect();
});

it('labels each role with the place it actually works in', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id]);
    $this->admin->managedBarangays()->sync([$this->mine->id, $this->second->id]);

    expect($this->superAdmin->scopeLabel())->toBe('All Barangays')
        ->and($this->admin->fresh()->scopeLabel())->toBe("{$this->municipality->name}: ".collect([$this->mine->name, $this->second->name])->sort()->implode(', '))
        ->and($encoder->scopeLabel())->toBe("Barangay {$this->mine->name}");
});

it('blocks a deactivated account from signing in with a clear message', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id, 'password' => 'secret-pass-123', 'deactivated_at' => now()]);

    $this->post(route('login'), ['email' => $encoder->email, 'password' => 'secret-pass-123'])
        ->assertSessionHasErrors(['email' => 'This account is deactivated. Please contact your administrator.']);
    $this->assertGuest();

    $this->post(route('login'), ['email' => $encoder->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');
});

it('signs out an already logged in account as soon as it is deactivated', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id]);

    $this->actingAs($encoder)->get(route('dashboard'))->assertOk();
    $this->actingAs($this->admin)->post(route('admin.users.deactivate', $encoder))->assertSessionHas('success');

    $this->actingAs($encoder->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('lets an admin deactivate and reactivate only their own encoders', function () {
    $mine = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id]);
    $foreign = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->other->id]);

    $this->actingAs($this->admin)->post(route('admin.users.deactivate', $mine))->assertRedirect();
    expect($mine->fresh()->isActive())->toBeFalse();

    $this->actingAs($this->admin)->post(route('admin.users.reactivate', $mine))->assertRedirect();
    expect($mine->fresh()->isActive())->toBeTrue();

    $this->actingAs($this->admin)->post(route('admin.users.deactivate', $foreign))->assertForbidden();
    $this->actingAs($this->admin)->post(route('admin.users.deactivate', $this->superAdmin))->assertForbidden();
});

it('never lets someone deactivate themselves or the last active super admin', function () {
    $this->actingAs($this->superAdmin)->post(route('admin.users.deactivate', $this->superAdmin))->assertForbidden();

    $second = User::factory()->withRole(RoleName::SuperAdmin)->create();
    $this->actingAs($this->superAdmin)->post(route('admin.users.deactivate', $second))->assertRedirect();
    expect($second->fresh()->isActive())->toBeFalse();

    $third = User::factory()->withRole(RoleName::SuperAdmin)->create(['deactivated_at' => now()]);
    $this->actingAs($second->fresh()->forceFill(['deactivated_at' => null]))->post(route('admin.users.deactivate', $this->superAdmin))->assertRedirect();
    expect($this->superAdmin->fresh()->isActive())->toBeTrue()->and($third->isActive())->toBeFalse();
});

it('refuses to delete an account that has records but deletes one without', function () {
    $approver = User::factory()->withRole(RoleName::Admin)->create();
    $resident = Resident::factory()->pending()->create(['approved_by_user_id' => $approver->id]);
    $unused = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id]);

    $this->actingAs($this->superAdmin)->delete(route('admin.users.destroy', $approver))->assertSessionHasErrors('user');
    expect(User::query()->find($approver->id))->not->toBeNull()
        ->and($resident->fresh()->approved_by_user_id)->toBe($approver->id);

    $this->actingAs($this->superAdmin)->delete(route('admin.users.destroy', $unused))->assertSessionHasNoErrors();
    expect(User::query()->find($unused->id))->toBeNull();
});
