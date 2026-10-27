<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\User;

beforeEach(function () {
    $this->superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create();
});

it('lets a super admin add a municipality and then a barangay to it', function () {
    $this->actingAs($this->superAdmin);

    visit('/admin/municipalities')
        ->click('Add municipality')
        ->type('#m-name', 'Lubao')
        ->type('#m-province', 'Pampanga')
        ->press('Save')
        ->assertSee('Lubao')
        ->press('Add barangay')
        ->type('#b-name', 'Santa Cruz')
        ->type('#b-code', 'sc')
        ->press('Save')
        ->assertSee('Santa Cruz')
        ->assertNoJavaScriptErrors();

    $municipality = Municipality::query()->where('name', 'Lubao')->firstOrFail();
    $barangay = Barangay::query()->where('name', 'Santa Cruz')->firstOrFail();

    expect($municipality->province)->toBe('Pampanga')
        ->and($barangay->municipality_id)->toBe($municipality->id)
        ->and($barangay->code)->toBe('SC');
});

it('keeps an admin out of the super admin screens', function () {
    $municipality = Municipality::factory()->create();
    $admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $municipality->id]);
    $this->actingAs($admin);

    visit('/admin/municipalities')
        ->assertSee('Your account cannot open this page')
        ->assertNoJavaScriptErrors();
});
