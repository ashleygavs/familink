<?php

use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;

beforeEach(function () {
    $municipality = Municipality::factory()->create();
    $this->barangay = Barangay::factory()->create(['municipality_id' => $municipality->id, 'name' => 'San Jose', 'code' => 'SJ']);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id, 'name' => 'Erin Encoder']);
});

it('lets an encoder register a new resident step by step', function () {
    $this->actingAs($this->encoder);

    visit('/encoder/register')
        ->assertSee('Start a new family record')
        ->click('Start a new family record')
        ->press('Next')
        ->type('#first_name', 'Maria')
        ->type('#last_name', 'Dela Cruz')
        ->type('#birthdate', '1990-05-10')
        ->click('#gender')
        ->click('Female')
        ->click('#civil_status')
        ->click('Single')
        ->press('Next')
        ->assertSee('Link or list the people this resident is related to')
        ->press('Next')
        ->click('Create a new household')
        ->type('#street_address', '12 Rizal Street, Purok 3')
        ->check('#is_head')
        ->press('Next')
        ->assertSee('Review and save')
        ->assertSee('Maria')
        ->assertSee('12 Rizal Street, Purok 3')
        ->press('Save Resident')
        ->assertSee('Maria Dela Cruz')
        ->assertSee('FL-')
        ->assertNoJavaScriptErrors();

    $resident = Resident::query()->where('last_name', 'Dela Cruz')->firstOrFail();

    expect($resident->status)->toBe(ResidentStatus::Pending)
        ->and($resident->barangay_id)->toBe($this->barangay->id)
        ->and($resident->household->street_address)->toBe('12 Rizal Street, Purok 3')
        ->and(ResidentChange::query()->where('resident_id', $resident->id)->value('summary'))->toBe('Registered by Erin Encoder in San Jose.');
});

it('tells the encoder what is missing instead of moving on', function () {
    $this->actingAs($this->encoder);

    visit('/encoder/register')
        ->click('Start a new family record')
        ->press('Next')
        ->press('Next')
        ->assertSee('Please check the 5 highlighted items and try again.')
        ->assertSee('This is required.')
        ->assertSee('Tell us about the person being registered')
        ->assertNoJavaScriptErrors();
});
