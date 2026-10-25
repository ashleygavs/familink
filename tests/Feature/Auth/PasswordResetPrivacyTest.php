<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

it('gives the same answer whether or not the email has an account', function () {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]);
    $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody-here@example.com']);

    $known->assertSessionHasNoErrors();
    $unknown->assertSessionHasNoErrors();
    expect(session('status'))->toBe('If that email has an account, we have sent a link to reset the password.');
    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);
});

it('hides the forgot password link on the login page until mail is ready', function () {
    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('canResetPassword', false));

    config(['fortify.show_reset_link' => true]);

    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('canResetPassword', true));
});
