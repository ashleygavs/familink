<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

const REUSED_MESSAGE = 'Please choose a password you have not been using.';

it('refuses a new password that is the one the account already has', function () {
    $user = User::factory()->create(['password' => Hash::make('Current-Pass-123')]);

    $this->actingAs($user)->from(route('security.edit'))->put(route('user-password.update'), [
        'current_password' => 'Current-Pass-123',
        'password' => 'Current-Pass-123',
        'password_confirmation' => 'Current-Pass-123',
    ])->assertSessionHasErrors(['password' => REUSED_MESSAGE]);

    expect(Hash::check('Current-Pass-123', $user->fresh()->password))->toBeTrue();
});

it('accepts a different new password', function () {
    $user = User::factory()->create(['password' => Hash::make('Current-Pass-123')]);

    $this->actingAs($user)->from(route('security.edit'))->put(route('user-password.update'), [
        'current_password' => 'Current-Pass-123',
        'password' => 'Brand-New-Pass-456',
        'password_confirmation' => 'Brand-New-Pass-456',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('Brand-New-Pass-456', $user->fresh()->password))->toBeTrue();
});

it('refuses the same password when a forgotten one is reset', function () {
    Notification::fake();
    $user = User::factory()->create(['password' => Hash::make('Current-Pass-123')]);
    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'Current-Pass-123',
            'password_confirmation' => 'Current-Pass-123',
        ])->assertSessionHasErrors(['password' => REUSED_MESSAGE]);

        return true;
    });
});
