<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $municipality = Municipality::factory()->create(['name' => 'Bacolor']);
    $this->barangay = Barangay::factory()->create(['municipality_id' => $municipality->id, 'name' => 'San Jose', 'code' => 'SJ']);
    // Named so that the encoder is listed first and the super admin second.
    $this->encoder = User::factory()->withRole(RoleName::User)->create([
        'name' => 'Aaron Clerk', 'email' => 'aaron.clerk@example.com', 'barangay_id' => $this->barangay->id, 'password' => Hash::make('Old-password-1'),
    ]);
    $this->superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['name' => 'Sam Super']);
});

it('lets a super admin set a temporary password from the Edit form and then read it in that account\'s history', function () {
    $this->actingAs($this->superAdmin);

    visit('/admin/users')
        ->assertSee('aaron.clerk@example.com')
        ->click('xpath=(//button[normalize-space()="Edit"])[1]')
        ->assertSee('Leave empty to keep the current password')
        ->type('#staff-password', 'Temporary-pass-2')
        ->press('Save changes')
        ->wait(1)
        ->assertSee('Account updated for')
        ->click('xpath=(//button[normalize-space()="History"])[1]')
        ->wait(1)
        ->assertSee('A temporary password was set. The person must choose their own at the next login.')
        ->assertDontSee('Temporary-pass-2')
        ->assertNoJavaScriptErrors();

    expect($this->encoder->fresh()->must_change_password)->toBeTrue()
        ->and(Hash::check('Temporary-pass-2', $this->encoder->fresh()->password))->toBeTrue();
});

it('leaves the password alone when the box is left empty', function () {
    $this->actingAs($this->superAdmin);

    visit('/admin/users')
        ->click('xpath=(//button[normalize-space()="Edit"])[1]')
        ->press('Save changes')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($this->encoder->fresh()->must_change_password)->toBeFalse()
        ->and(Hash::check('Old-password-1', $this->encoder->fresh()->password))->toBeTrue();
});
