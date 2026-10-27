<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Logs a person in through the real login page, the way staff do.
 */
function logInThroughThePage(string $email, string $password): mixed
{
    return visit('/login')
        ->type('#email', $email)
        ->type('#password', $password)
        ->click('Log In to FamiLink');
}

beforeEach(function () {
    $this->municipality = Municipality::factory()->create();
    $this->barangay = Barangay::factory()->create(['municipality_id' => $this->municipality->id]);
});

it('shows a plain message for a wrong password and stays on the login page', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);

    logInThroughThePage($encoder->email, 'not-the-password')
        ->assertSee('The email or password is not correct')
        ->assertPathIs('/login')
        ->assertNoJavaScriptErrors();
});

it('sends each role to its own first page after login', function (RoleName $role) {
    $user = User::factory()->withRole($role)->create([
        'barangay_id' => $role === RoleName::User ? $this->barangay->id : null,
        'municipality_id' => $role === RoleName::Admin ? $this->municipality->id : null,
    ]);

    if ($role === RoleName::Admin) {
        $user->managedBarangays()->sync([$this->barangay->id]);
    }

    logInThroughThePage($user->email, 'password')
        ->assertPathIs($role->homePath())
        ->assertNoJavaScriptErrors();
})->with([
    'encoder' => RoleName::User,
    'admin' => RoleName::Admin,
    'stakeholder' => RoleName::Stakeholder,
    'super admin' => RoleName::SuperAdmin,
]);

it('makes a person choose their own password when an administrator set it', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create([
        'barangay_id' => $this->barangay->id,
        'password' => Hash::make('Temporary-pass-1'),
        'must_change_password' => true,
    ]);

    logInThroughThePage($encoder->email, 'Temporary-pass-1')
        ->assertPathIs('/settings/security')
        ->assertSee('Please choose your own password')
        ->type('#current_password', 'Temporary-pass-1')
        ->type('#password', 'My-own-new-pass-2')
        ->type('#password_confirmation', 'My-own-new-pass-2')
        ->press('Save')
        ->assertNoJavaScriptErrors();

    expect($encoder->fresh()->must_change_password)->toBeFalse()
        ->and(Hash::check('My-own-new-pass-2', $encoder->fresh()->password))->toBeTrue();

    visit('/encoder/register')->assertPathIs('/encoder/register')->assertDontSee('Please choose your own password');
});
