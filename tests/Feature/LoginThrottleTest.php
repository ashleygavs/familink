<?php

use App\Models\User;

beforeEach(function () {
    User::factory()->create(['email' => 'victim@example.com']);

    $this->tryLogin = fn (int $attempt, array $server = [], ?string $forwardedFor = null) => $this->withServerVariables($server)
        ->withHeaders($forwardedFor === null ? [] : ['X-Forwarded-For' => $forwardedFor])
        ->post('/login', ['email' => 'victim@example.com', 'password' => "wrong-{$attempt}"])
        ->getStatusCode();
});

it('blocks repeated wrong passwords from the same address', function () {
    $statuses = array_map(fn (int $attempt) => ($this->tryLogin)($attempt), range(1, 7));

    expect($statuses[4])->not->toBe(429)->and($statuses[5])->toBe(429);
});

it('ignores a forwarded address from someone who is not a trusted proxy', function () {
    // A visitor reaching the app directly cannot choose the address the lockout counts.
    $statuses = array_map(
        fn (int $attempt) => ($this->tryLogin)($attempt, ['REMOTE_ADDR' => '203.0.113.9'], "10.0.0.{$attempt}"),
        range(1, 7),
    );

    expect($statuses[5])->toBe(429);
});

it('still blocks one account when the attacker changes address every time', function () {
    // Even from a trusted proxy that passes along any address, the email alone is limited too.
    $statuses = array_map(
        fn (int $attempt) => ($this->tryLogin)($attempt, ['REMOTE_ADDR' => '127.0.0.1'], "10.0.0.{$attempt}"),
        range(1, 12),
    );

    expect($statuses[9])->not->toBe(429)->and($statuses[10])->toBe(429);
});

it('trusts only the local computer as a proxy unless told otherwise', function () {
    expect(config('trustedproxy.proxies'))->toBe(['127.0.0.1', '::1']);
});
