<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Municipality;
use App\Models\User;
use App\Providers\AppServiceProvider;

it('turns debug off on a live site even when the setting was left on', function () {
    config(['app.debug' => true]);
    app()->detectEnvironment(fn (): string => 'production');

    (new AppServiceProvider(app()))->register();

    expect(config('app.debug'))->toBeFalse();
});

it('leaves debug alone while developing', function () {
    config(['app.debug' => true]);
    app()->detectEnvironment(fn (): string => 'local');

    (new AppServiceProvider(app()))->register();

    expect(config('app.debug'))->toBeTrue();
});

it('lets only a logged-in super admin read what the Inertia developer tools recorded', function () {
    expect(config('inertia.devtools.middleware'))->toBe(['web', 'auth', 'role:super_admin']);
});

it('does not serve stored files through an address of its own', function () {
    expect(config('filesystems.disks.local.serve'))->toBeFalse();

    $this->get('/storage/anything.txt')->assertNotFound();
});

it('answers a household review for a missing or outside household with not found, and a real mistake with a message', function () {
    $municipality = Municipality::factory()->create();
    $mine = Barangay::factory()->create(['municipality_id' => $municipality->id]);
    $other = Barangay::factory()->create(['municipality_id' => $municipality->id]);
    $admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $municipality->id]);
    $admin->managedBarangays()->sync([$mine->id]);
    $inside = Household::factory()->create(['barangay_id' => $mine->id]);
    $outside = Household::factory()->create(['barangay_id' => $other->id]);
    $post = fn (array $ids) => $this->actingAs($admin)->post(route('admin.household-reviews.store'), [...$ids, 'reason' => 'Looked at both.']);

    $post(['first_household_id' => 999998, 'second_household_id' => 999999])->assertNotFound();
    $post(['first_household_id' => $outside->id, 'second_household_id' => 999999])->assertNotFound();
    $post(['first_household_id' => $inside->id, 'second_household_id' => 999999])->assertSessionHasErrors('second_household_id');
});
