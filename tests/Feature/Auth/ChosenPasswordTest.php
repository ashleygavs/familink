<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\User;
use App\Models\UserChange;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->municipality = Municipality::factory()->create();
    $this->barangay = Barangay::factory()->create(['municipality_id' => $this->municipality->id]);
    $this->superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create();
    $this->admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $this->municipality->id]);
    $this->admin->managedBarangays()->sync([$this->barangay->id]);
});

it('asks a person to choose their own password when an admin creates their account', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), [
        'name' => 'New Encoder', 'email' => 'new.encoder@example.com', 'role' => 'user', 'barangay_id' => $this->barangay->id,
        'password' => 'A-temporary-pass-1',
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'new.encoder@example.com')->value('must_change_password'))->toBeTrue();
});

it('asks for a new password when an admin sets one on an existing account, and says so in the history', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);

    $this->actingAs($this->admin)->put(route('admin.users.update', $encoder), [
        'name' => $encoder->name, 'email' => $encoder->email, 'role' => 'user', 'barangay_id' => $this->barangay->id, 'password' => 'Another-temp-pass-2',
    ])->assertSessionHasNoErrors();

    expect($encoder->fresh()->must_change_password)->toBeTrue()
        ->and(UserChange::query()->latest('id')->value('summary'))->toContain('temporary password');
});

it('does not ask when a person sets their own password, or leaves it empty', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);

    $this->actingAs($this->admin)->put(route('admin.users.update', $encoder), [
        'name' => 'Renamed Encoder', 'email' => $encoder->email, 'role' => 'user', 'barangay_id' => $this->barangay->id, 'password' => '',
    ])->assertSessionHasNoErrors();

    expect($encoder->fresh()->must_change_password)->toBeFalse();

    $this->actingAs($this->superAdmin)->put(route('admin.users.update', $this->superAdmin), [
        'name' => $this->superAdmin->name, 'email' => $this->superAdmin->email, 'role' => 'super_admin', 'password' => 'My-own-new-pass-3',
    ])->assertSessionHasNoErrors();

    expect($this->superAdmin->fresh()->must_change_password)->toBeFalse();
});

it('sends someone who must choose a password to the password page, and nowhere else', function () {
    $person = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id, 'must_change_password' => true]);

    $this->actingAs($person)->get(route('dashboard'))->assertRedirect(route('security.edit'));
    $this->actingAs($person)->get(route('encoder.scanner'))->assertRedirect(route('security.edit'));
    $this->actingAs($person)->get(route('security.edit'))->assertOk();
    $this->actingAs($person)->get(route('password.confirm'))->assertOk();
});

it('gives a plain answer to a scanner or search request while the password is not chosen', function () {
    $person = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id, 'must_change_password' => true]);

    $this->actingAs($person)->getJson(route('encoder.residents.search', ['q' => 'abc']))
        ->assertForbidden()->assertJson(['message' => 'An administrator set this password for you. Please choose your own now.']);
});

it('lets the person save their own password and log out, then carries on normally', function () {
    $person = User::factory()->withRole(RoleName::User)->create([
        'barangay_id' => $this->barangay->id, 'must_change_password' => true, 'password' => Hash::make('Temporary-pass-4'),
    ]);

    $this->actingAs($person)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.must_change_password', true));

    $this->actingAs($person)->put(route('user-password.update'), [
        'current_password' => 'Temporary-pass-4', 'password' => 'My-chosen-pass-5', 'password_confirmation' => 'My-chosen-pass-5',
    ])->assertSessionHasNoErrors();

    expect($person->fresh()->must_change_password)->toBeFalse();
    $this->actingAs($person->fresh())->get(route('encoder.scanner'))->assertOk();

    $flagged = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id, 'must_change_password' => true]);
    $this->actingAs($flagged)->post(route('logout'))->assertRedirect();
});

it('resets a password from the server, shows it once, signs the person out and records it', function () {
    $user = User::factory()->withRole(RoleName::SuperAdmin)->create(['email' => 'locked.out@example.com', 'password' => Hash::make('forgotten')]);
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    $exit = Artisan::call('familink:reset-password', ['email' => 'locked.out@example.com', '--force' => true]);
    preg_match('/[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/', Artisan::output(), $found);

    expect($exit)->toBe(0)->and($found)->toHaveCount(1)
        ->and(Hash::check($found[0], $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->must_change_password)->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(UserChange::query()->where('user_id', $user->id)->latest('id')->value('summary'))->toContain('reset from the server');
});

it('asks before resetting, and refuses an unknown email', function () {
    $user = User::factory()->withRole(RoleName::Stakeholder)->create(['email' => 'someone@example.com', 'password' => Hash::make('keep-this-one')]);

    $this->artisan('familink:reset-password', ['email' => 'someone@example.com'])->expectsConfirmation('Reset the password for '.$user->name.' ('.$user->role->name.')? They will be signed out everywhere.', 'no')->assertFailed();
    expect(Hash::check('keep-this-one', $user->fresh()->password))->toBeTrue();

    $this->artisan('familink:reset-password', ['email' => 'nobody@example.com', '--force' => true])->assertFailed();
});
