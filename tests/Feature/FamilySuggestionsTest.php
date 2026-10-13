<?php

use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['code' => 'SJ']);
    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);
});

it('returns suggestions and claims for an encoder, limited to their barangay', function () {
    $father = Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz', 'gender' => 'Male', 'birthdate' => '1980-03-14',
    ]);
    $elsewhere = Household::factory()->create();
    Resident::factory()->create([
        'household_id' => $elsewhere->id, 'first_name' => 'Karl', 'last_name' => 'Dela Cruz', 'gender' => 'Male', 'birthdate' => '1979-03-14',
    ]);
    $claim = Resident::factory()->listed()->create([
        'first_name' => 'Pedro', 'last_name' => 'Dela Cruz', 'gender' => 'Male', 'birthdate' => '2008-05-01', 'listed_by_resident_id' => $father->id,
    ]);

    $this->actingAs($this->encoder)
        ->getJson(route('encoder.family-suggestions', [
            'first_name' => 'Pedro', 'last_name' => 'Dela Cruz', 'birthdate' => '2008-05-01', 'gender' => 'Male',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data.father')
        ->assertJsonPath('data.father.0.resident.id', $father->id)
        ->assertJsonPath('data.father.0.confidence', 'high')
        ->assertJsonPath('data.claims.0.resident.id', $claim->id)
        ->assertJsonPath('data.claims.0.resident.listed_by', 'Juan Dela Cruz')
        ->assertJsonStructure(['data' => ['claims', 'father', 'mother', 'spouse', 'children']]);
});

it('requires a name and birthdate and the encoder role', function () {
    $this->actingAs($this->encoder)->getJson(route('encoder.family-suggestions'))->assertUnprocessable();

    $this->actingAs(User::factory()->withRole(RoleName::Stakeholder)->create())
        ->getJson(route('encoder.family-suggestions', ['first_name' => 'A', 'last_name' => 'B', 'birthdate' => '2000-01-01']))
        ->assertForbidden();
});

it('shows suggested links to admins and applies a confirmed link', function () {
    $admin = User::factory()->withRole(RoleName::Admin)->create();
    $father = Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'gender' => 'Male', 'birthdate' => '1980-03-14',
    ]);
    $child = Resident::factory()->pending()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'first_name' => 'Pedro', 'last_name' => 'Dela Cruz', 'gender' => 'Male', 'birthdate' => '2008-05-01',
    ]);

    $this->actingAs($admin)->get(route('admin.approvals'))
        ->assertInertia(fn ($page) => $page
            ->where('pending.data.0.suggestions.father.0.resident.id', $father->id)
            ->where('pending.data.0.suggestions.claims', []));

    $this->actingAs($admin)->post(route('admin.approvals.links', $child), ['father_id' => $father->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($child->fresh()->father_id)->toBe($father->id);

    $this->actingAs($admin)->get(route('admin.approvals'))
        ->assertInertia(fn ($page) => $page->where('pending.data.0.suggestions.father', []));
});

it('adopts children and links a spouse from the approvals page', function () {
    $admin = User::factory()->withRole(RoleName::Admin)->create();
    $parent = Resident::factory()->pending()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id, 'gender' => 'Female', 'birthdate' => '1983-07-02',
    ]);
    $spouse = Resident::factory()->create(['gender' => 'Male', 'birthdate' => '1980-01-01']);
    $childHousehold = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $child = Resident::factory()->create([
        'household_id' => $childHousehold->id, 'barangay_id' => $this->barangay->id, 'birthdate' => '2008-05-01',
    ]);

    $this->actingAs($admin)->post(route('admin.approvals.links', $parent), ['spouse_id' => $spouse->id, 'dependent_ids' => [$child->id]])
        ->assertSessionHasNoErrors();

    expect($parent->fresh()->spouse_id)->toBe($spouse->id)
        ->and($spouse->fresh()->spouse_id)->toBe($parent->id)
        ->and($child->fresh()->mother_id)->toBe($parent->id)
        ->and($child->fresh()->household_id)->toBe($this->household->id);
});

it('refuses to overwrite an existing link, link nothing, or touch decided residents', function () {
    $admin = User::factory()->withRole(RoleName::Admin)->create();
    $father = Resident::factory()->create(['gender' => 'Male', 'birthdate' => '1960-01-01']);
    $other = Resident::factory()->create(['gender' => 'Male', 'birthdate' => '1961-01-01']);
    $linked = Resident::factory()->pending()->create(['birthdate' => '2000-01-01', 'father_id' => $father->id]);
    $approved = Resident::factory()->create(['birthdate' => '2000-01-01']);

    $this->actingAs($admin)->post(route('admin.approvals.links', $linked), ['father_id' => $other->id])->assertSessionHasErrors('father_id');
    $this->actingAs($admin)->post(route('admin.approvals.links', $linked), [])->assertSessionHasErrors('father_id');
    $this->actingAs($admin)->post(route('admin.approvals.links', $approved), ['father_id' => $father->id])->assertStatus(409);

    expect($linked->fresh()->father_id)->toBe($father->id);
});

it('keeps the links endpoint away from encoders', function () {
    $resident = Resident::factory()->pending()->create();

    $this->actingAs($this->encoder)->post(route('admin.approvals.links', $resident), ['father_id' => 1])->assertForbidden();

    expect($resident->fresh()->status)->toBe(ResidentStatus::Pending);
});
