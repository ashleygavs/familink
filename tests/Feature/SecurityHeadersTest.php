<?php

use App\Enums\RoleName;
use App\Models\User;

it('sends the safety headers on the login page', function () {
    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('sends the safety headers and no-store on a page behind login', function () {
    $user = User::factory()->withRole(RoleName::SuperAdmin)->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()->assertHeader('X-Frame-Options', 'DENY');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('keeps the scripts the page runs on a list, with a fresh code each time', function () {
    $first = $this->get(route('login'));
    $second = $this->get(route('login'));

    $policy = $first->headers->get('Content-Security-Policy-Report-Only');
    preg_match("/'nonce-([^']+)'/", $policy, $found);

    expect($policy)->toContain("frame-ancestors 'none'")->toContain("object-src 'none'")
        ->and($found)->toHaveCount(2)
        ->and($first->getContent())->toContain('nonce="'.$found[1].'"')
        ->and($second->headers->get('Content-Security-Policy-Report-Only'))->not->toBe($policy);
});

it('only asks for secure transport when the visit is already secure', function () {
    $this->get(route('login'))->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});
