<?php

use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard shows summary counts for admins', function () {
    $this->seed(DatabaseSeeder::class);
    Resident::factory()->pending()->create();

    $this->actingAs(User::query()->where('email', 'bac.sanjose.admin@familink.test')->first())
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('stats.approvedResidents', 3)
            ->where('stats.pendingResidents', 1)
            ->where('stats.households', 2)
            ->where('stats.reliefClaims', 0));
});

test('encoders only see the counts of their own barangay', function () {
    $this->seed(DatabaseSeeder::class);
    // A resident of another barangay, who the San Jose encoder must not count.
    Resident::factory()->create(['household_id' => Household::factory()->create(['barangay_id' => Barangay::factory()])->id]);

    $encoder = User::query()->where('email', 'bac.sanjose.encoder@familink.test')->first();

    $this->actingAs($encoder)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('stats.approvedResidents', 3)
            ->where('stats.households', 1));
});
