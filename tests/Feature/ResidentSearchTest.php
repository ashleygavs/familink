<?php

use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $this->encoder = User::factory()->withRole(RoleName::User)->create();
});

it('finds residents by partial name across barangays', function () {
    Resident::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
    Resident::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);

    $this->actingAs($this->encoder)->getJson(route('encoder.residents.search', ['q' => 'jua cruz']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.full_name', fn ($name) => str_contains($name, 'Juan'))
        ->assertJsonStructure(['data' => [['id', 'full_name', 'system_id_no', 'birthdate', 'gender', 'barangay_name', 'household' => ['id', 'household_code', 'street_address']]]]);
});

it('finds residents by system id and never exposes the qr hash', function () {
    $resident = Resident::factory()->create();

    $response = $this->actingAs($this->encoder)->getJson(route('encoder.residents.search', ['q' => $resident->system_id_no]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $resident->id);

    expect($response->getContent())->not->toContain($resident->qr_code_hash);
});

it('hides archived residents and limits results to ten', function () {
    Resident::factory()->create(['first_name' => 'Zed', 'status' => ResidentStatus::Archived]);
    Resident::factory()->count(12)->create(['first_name' => 'Zed']);

    $this->actingAs($this->encoder)->getJson(route('encoder.residents.search', ['q' => 'Zed']))
        ->assertJsonCount(10, 'data');
});

it('applies the parent, age and spouse filters', function () {
    $barangay = Barangay::factory()->create();
    $male = Resident::factory()->create(['first_name' => 'Pat', 'gender' => 'Male', 'birthdate' => '1960-01-01', 'barangay_id' => $barangay->id]);
    Resident::factory()->create(['first_name' => 'Pat', 'gender' => 'Female', 'birthdate' => '1990-01-01']);
    $withFather = Resident::factory()->create(['first_name' => 'Pat', 'birthdate' => '2012-01-01', 'father_id' => $male->id]);

    $search = fn (array $filters) => $this->actingAs($this->encoder)
        ->getJson(route('encoder.residents.search', ['q' => 'Pat', ...$filters]))
        ->json('data.*.id');

    expect($search(['gender' => 'Male', 'older_than' => '1980-01-01']))->toBe([$male->id])
        ->and($search(['born_after' => '2000-01-01', 'unlinked_parent' => 'father']))->toBe([])
        ->and($search(['born_after' => '2000-01-01', 'unlinked_parent' => 'mother']))->toBe([$withFather->id])
        ->and($search(['barangay_id' => $barangay->id]))->toBe([$male->id]);
});

it('requires at least two characters and the right role', function () {
    $this->actingAs($this->encoder)->getJson(route('encoder.residents.search', ['q' => 'a']))->assertUnprocessable();

    $this->actingAs(User::factory()->withRole(RoleName::Stakeholder)->create())
        ->getJson(route('encoder.residents.search', ['q' => 'abc']))
        ->assertForbidden();

    auth()->logout();
    $this->getJson(route('encoder.residents.search', ['q' => 'abc']))->assertUnauthorized();
});

it('searches households of a single barangay by code, address or head', function () {
    $barangay = Barangay::factory()->create(['code' => 'SJ']);
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $barangay->id]);
    $mine = Household::factory()->create(['barangay_id' => $barangay->id, 'household_code' => 'HH-SJ-0007', 'street_address' => '5 Mabini St']);
    $head = Resident::factory()->for($mine)->create(['first_name' => 'Carlos', 'barangay_id' => $barangay->id]);
    $mine->update(['head_resident_id' => $head->id]);
    Household::factory()->create(['household_code' => 'HH-XX-0007']);

    foreach (['0007', 'Mabini', 'Carlos'] as $term) {
        $this->actingAs($encoder)->getJson(route('encoder.households.search', ['q' => $term, 'barangay_id' => $barangay->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.household_code', 'HH-SJ-0007')
            ->assertJsonPath('data.0.members_count', 1);
    }
});
