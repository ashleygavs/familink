<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('the name can be updated and the account stays verified', function () {
    $user = User::factory()->create();
    $email = $user->email;

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Test User']);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe($email);
    expect($user->email_verified_at)->not->toBeNull();
});

test('an email sent with the profile form is ignored, so staff cannot lock themselves out', function () {
    $barangay = Barangay::factory()->create();
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $barangay->id]);
    $email = $encoder->email;

    $this->actingAs($encoder)
        ->patch(route('profile.update'), ['name' => 'Renamed Encoder', 'email' => 'someone.else@example.test'])
        ->assertSessionHasNoErrors();

    $encoder->refresh();

    expect($encoder->name)->toBe('Renamed Encoder')
        ->and($encoder->email)->toBe($email)
        ->and($encoder->email_verified_at)->not->toBeNull();

    $this->actingAs($encoder)->get(route('dashboard'))->assertOk();
});

test('the profile page shows the email as it is, without a way to change it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/profile')->missing('mustVerifyEmail'));
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
