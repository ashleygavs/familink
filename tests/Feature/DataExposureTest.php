<?php

use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Municipality;
use App\Models\ReliefEvent;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $municipality = Municipality::factory()->create();
    $this->mine = Barangay::factory()->create(['municipality_id' => $municipality->id, 'name' => 'Alpha']);
    $this->other = Barangay::factory()->create(['municipality_id' => $municipality->id, 'name' => 'Bravo']);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id]);
    $this->event = ReliefEvent::factory()->create(['municipality_id' => $municipality->id]);

    $this->person = function (Barangay $barangay, array $extra = []) {
        $household = Household::factory()->create(['barangay_id' => $barangay->id, 'street_address' => 'Secret Street 9']);

        return Resident::factory()->create([
            'household_id' => $household->id, 'barangay_id' => $barangay->id, 'last_name' => 'Tester',
            'middle_name' => null, 'suffix' => null, ...$extra,
        ]);
    };

    $this->father = ($this->person)($this->other, ['first_name' => 'Faraway', 'gender' => 'Male', 'birthdate' => '1960-05-17']);
    $this->sibling = ($this->person)($this->other, ['first_name' => 'Sibling', 'father_id' => $this->father->id, 'birthdate' => '1990-03-02']);
    $this->nearby = ($this->person)($this->mine, ['first_name' => 'Nearby', 'birthdate' => '1985-01-30']);

    $this->revealsNothingPrivate = function (array $row): void {
        expect($row['system_id_no'])->toBeNull()
            ->and($row['birthdate'])->toBeNull()
            ->and($row['household'])->toBeNull()
            ->and($row['birth_year'])->toBeInt()
            ->and($row['barangay_name'])->toBe('Bravo');
    };
});

it('shows residents of other barangays in a search with only what tells people apart', function () {
    $rows = $this->actingAs($this->encoder)->getJson(route('encoder.residents.search', ['q' => 'Faraway']))->assertOk()->json('data');

    expect($rows)->toHaveCount(1);
    ($this->revealsNothingPrivate)($rows[0]);
    expect($rows[0]['birth_year'])->toBe(1960)->and($rows[0]['full_name'])->toBe('Faraway Tester');
});

it('still shows full details for residents of the encoder\'s own barangay', function () {
    $row = $this->actingAs($this->encoder)->getJson(route('encoder.residents.search', ['q' => 'Nearby']))->json('data.0');

    expect($row['system_id_no'])->toBe($this->nearby->system_id_no)
        ->and($row['birthdate'])->toBe('1985-01-30')
        ->and($row['household']['street_address'])->toBe('Secret Street 9');
});

it('keeps the private details of other barangays out of the family-relatives answer', function () {
    $response = $this->actingAs($this->encoder)->getJson(route('encoder.family-relatives', ['father_id' => $this->father->id]))->assertOk();

    expect($response->getContent())->toContain('Sibling')
        ->and($response->getContent())->not->toContain('Secret Street')->not->toContain('1990-03-02')->not->toContain($this->sibling->system_id_no);
});

it('keeps the private details of other barangays out of the relative suggestions', function () {
    $response = $this->actingAs($this->encoder)->getJson(route('encoder.relative-suggestions', [
        'relatives' => [['id' => $this->sibling->id, 'relation' => 'sibling']],
    ]))->assertOk();

    expect($response->getContent())->toContain('Faraway')
        ->and($response->getContent())->not->toContain('Secret Street')->not->toContain('1960-05-17')->not->toContain($this->father->system_id_no);
});

it('still shows a record listed by a relative in full so the person can claim it', function () {
    $listed = Resident::factory()->listed()->create(['first_name' => 'Listed', 'last_name' => 'Tester', 'birthdate' => '1999-09-09']);

    $row = $this->actingAs($this->encoder)->getJson(route('encoder.residents.search', ['q' => 'Listed']))->json('data.0');

    expect($row['id'])->toBe($listed->id)->and($row['birthdate'])->toBe('1999-09-09')->and($row['system_id_no'])->toBe($listed->system_id_no);
});

it('does not say who lives in another barangay when an ID is typed at the scanner', function () {
    $response = $this->actingAs($this->encoder)->postJson(route('encoder.scan'), ['event_id' => $this->event->id, 'code' => $this->father->system_id_no])
        ->assertOk()
        ->assertJson(['granted' => false, 'code' => 'outside_area', 'resident' => null]);

    expect($response->getContent())->not->toContain('Faraway')->not->toContain($this->father->system_id_no)->not->toContain('Bravo');
});

it('limits how fast the scanner and the searches can be used', function () {
    foreach (range(1, 60) as $unused) {
        $this->actingAs($this->encoder)->postJson(route('encoder.scan'), ['event_id' => $this->event->id, 'code' => 'unknown'])->assertOk();
    }

    $this->actingAs($this->encoder)->postJson(route('encoder.scan'), ['event_id' => $this->event->id, 'code' => 'unknown'])->assertStatus(429);

    foreach (range(1, 120) as $unused) {
        $this->actingAs($this->encoder)->getJson(route('encoder.residents.search', ['q' => 'Nearby']))->assertOk();
    }

    $this->actingAs($this->encoder)->getJson(route('encoder.residents.search', ['q' => 'Nearby']))->assertStatus(429);
});

it('answers a deactivated account with a plain message, not a redirect to the login page', function () {
    $this->encoder->forceFill(['deactivated_at' => now()])->save();

    $this->actingAs($this->encoder)->postJson(route('encoder.scan'), ['event_id' => $this->event->id, 'code' => 'x'])
        ->assertUnauthorized()
        ->assertJson(['message' => 'This account is deactivated. Please contact your administrator.']);
});

it('still sends a deactivated person on a normal page to the login page', function () {
    $this->encoder->forceFill(['deactivated_at' => now()])->save();

    $this->actingAs($this->encoder)->get(route('encoder.scanner'))->assertRedirect(route('login'));
});

it('treats an archived neighbour like any other: never showing it', function () {
    ($this->person)($this->mine, ['first_name' => 'Gone', 'status' => ResidentStatus::Archived]);

    expect($this->actingAs($this->encoder)->getJson(route('encoder.residents.search', ['q' => 'Gone']))->json('data'))->toBe([]);
});
