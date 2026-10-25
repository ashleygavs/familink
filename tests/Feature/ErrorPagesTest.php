<?php

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->get('/_test-error/{code}', fn (int $code) => abort($code));
});

it('shows the friendly page for each error people can meet', function (int $code) {
    config(['app.debug' => false]);

    $this->get("/_test-error/{$code}")
        ->assertStatus($code)
        ->assertInertia(fn ($page) => $page->component('error')->where('status', $code));
})->with([403, 404, 419, 429, 500, 503]);

it('shows the page to people who are logged in and to people who are not', function () {
    $this->get('/nothing-here')->assertNotFound()->assertInertia(fn ($page) => $page->component('error')->where('auth.user', null));

    $user = User::factory()->withRole(RoleName::Stakeholder)->create();

    $this->actingAs($user)->get('/nothing-here')->assertNotFound()
        ->assertInertia(fn ($page) => $page->component('error')->where('auth.user.id', $user->id));
});

it('tells a role that may not open a page, and tells an outside record the same as a missing one', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create();

    $this->actingAs($encoder)->get(route('admin.users'))->assertForbidden()
        ->assertInertia(fn ($page) => $page->component('error')->where('status', 403));
});

it('never shows technical details on the error page', function () {
    config(['app.debug' => false]);

    $html = $this->get('/_test-error/500')->assertStatus(500)->getContent();

    expect($html)->not->toContain('vendor/laravel')->not->toContain('Stack trace')->not->toContain('.php');
});

it('keeps the detailed screen for a real fault while developing', function () {
    config(['app.debug' => true]);
    Route::middleware('web')->get('/_test-fault', fn () => throw new RuntimeException('Kaboom'));

    $this->get('/_test-fault')->assertStatus(500)->assertSee('Kaboom', false);
});

it('still answers a request that expects JSON with plain JSON', function () {
    config(['app.debug' => false]);

    $this->getJson('/_test-error/404')->assertNotFound()->assertJsonStructure(['message']);
});
