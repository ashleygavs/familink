<?php

use App\Models\User;

it('limits password reset requests from one address', function () {
    User::factory()->create(['email' => 'someone@example.com']);

    $statuses = array_map(
        fn (int $attempt) => $this->post('/forgot-password', ['email' => 'someone@example.com'])->getStatusCode(),
        range(1, 7),
    );

    expect($statuses[4])->not->toBe(429)->and($statuses[5])->toBe(429);
});

it('limits the password reset form itself', function () {
    $statuses = array_map(
        fn (int $attempt) => $this->post('/reset-password', ['token' => 'bad', 'email' => 'x@example.com', 'password' => 'a', 'password_confirmation' => 'a'])->getStatusCode(),
        range(1, 7),
    );

    expect($statuses[5])->toBe(429);
});

it('does not limit ordinary requests', function () {
    foreach (range(1, 10) as $unused) {
        $this->get('/login')->assertOk();
    }
});
