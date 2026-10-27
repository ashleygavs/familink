<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\User;
use App\Models\UserChange;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->municipality = Municipality::factory()->create(['name' => 'Bacolor']);
    $this->barangay = Barangay::factory()->create(['municipality_id' => $this->municipality->id, 'name' => 'San Jose', 'code' => 'SJ']);
    $this->superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['name' => 'Sam Super']);
});

it('lets a super admin create an encoder account that must choose its own password', function () {
    $this->actingAs($this->superAdmin);

    visit('/admin/users')
        ->assertSee('Staff accounts')
        ->click('Add account')
        ->type('#staff-email', 'new.encoder@example.com')
        ->type('#staff-password', 'Temporary-pass-1')
        ->click('#staff-barangay')
        ->click('[role=option]:has-text("San Jose")')
        ->press('Create account')
        ->wait(1)
        ->assertSee('new.encoder@example.com')
        ->assertNoJavaScriptErrors();

    $created = User::query()->where('email', 'new.encoder@example.com')->firstOrFail();

    expect($created->role->name)->toBe(RoleName::User->value)
        ->and($created->barangay_id)->toBe($this->barangay->id)
        ->and($created->must_change_password)->toBeTrue()
        ->and(UserChange::query()->where('user_id', $created->id)->value('summary'))->toContain('Created the account');
});

it('lets a super admin deactivate and reactivate an account, and records both', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id, 'email' => 'clerk@example.com']);
    $this->actingAs($this->superAdmin);

    visit('/admin/users')
        ->assertSee('clerk@example.com')
        ->press('Deactivate')
        ->click('button:has-text("Yes, deactivate")')
        ->wait(1)
        ->assertSee('Reactivate')
        ->press('Reactivate')
        ->wait(1)
        ->assertSee('Deactivate')
        ->assertNoJavaScriptErrors();

    // Both changes are written to the account's history. The account itself never did anything, so it can still be deleted.
    expect($encoder->fresh()->isActive())->toBeTrue()
        ->and(UserChange::query()->where('user_id', $encoder->id)->pluck('type')->sort()->values()->all())->toBe(['deactivated', 'reactivated']);
});
it('lets a super admin delete an account that never did anything', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id, 'email' => 'unused@example.com']);
    $this->actingAs($this->superAdmin);

    visit('/admin/users')
        ->assertSee('unused@example.com')
        ->press('Delete')
        ->click('button:has-text("Yes, delete")')
        ->wait(1)
        ->assertSee('Account deleted for')
        ->assertNoJavaScriptErrors();

    expect(User::query()->whereKey($encoder->id)->exists())->toBeFalse();
});
