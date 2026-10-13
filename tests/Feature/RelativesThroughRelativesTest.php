<?php

use App\Actions\Residents\FindFamilyMatches;
use App\Actions\Residents\FindRelativesThroughRelatives;
use App\Enums\RelativeKind;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['code' => 'SJ']);
    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);

    $this->make = fn (string $first, string $gender, string $birthdate, array $extra = []) => Resident::factory()->create([
        'household_id' => $this->household->id,
        'barangay_id' => $this->barangay->id,
        'first_name' => $first,
        'middle_name' => null,
        'last_name' => 'Cariño',
        'gender' => $gender,
        'birthdate' => $birthdate,
        ...$extra,
    ]);

    $this->father = ($this->make)('Mark', 'Male', '1984-10-10');
    $this->mother = ($this->make)('Jennifer', 'Female', '1984-01-21', ['last_name' => 'Mallari']);
    $this->marcus = ($this->make)('Marcus', 'Male', '2004-01-21', ['father_id' => $this->father->id, 'mother_id' => $this->mother->id]);
    $this->mckenzie = ($this->make)('McKenzie', 'Female', '2006-05-05', ['father_id' => $this->father->id, 'mother_id' => $this->mother->id]);
});

/**
 * @param  list<array{0: Resident, 1: RelativeKind}>  $chosen
 * @return array<string, array<string, mixed>>
 */
function derivedFrom(array $chosen): array
{
    $pairs = array_map(fn (array $entry): array => ['relative' => $entry[0], 'relation' => $entry[1]->value], $chosen);

    return collect(app(FindRelativesThroughRelatives::class)->handle($pairs))
        ->keyBy(fn (array $match) => "{$match['relation']}:{$match['resident']->first_name}")
        ->all();
}

it('suggests the parents of a confirmed cousin as uncle and aunt', function () {
    $derived = derivedFrom([[$this->marcus, RelativeKind::Cousin]]);

    expect($derived)->toHaveKeys(['uncle_aunt:Mark', 'uncle_aunt:Jennifer', 'cousin:McKenzie'])
        ->and($derived['uncle_aunt:Jennifer']['reasons'])->toContain('Parent of your cousin Marcus');
});

it('scores a person higher when two confirmed relatives point to them', function () {
    $single = derivedFrom([[$this->marcus, RelativeKind::Cousin]]);
    $both = derivedFrom([[$this->marcus, RelativeKind::Cousin], [$this->mckenzie, RelativeKind::Cousin]]);

    expect($both['uncle_aunt:Jennifer']['score'])->toBeGreaterThan($single['uncle_aunt:Jennifer']['score']);
});

it('never suggests a relative who is already chosen', function () {
    $derived = derivedFrom([[$this->marcus, RelativeKind::Cousin], [$this->mckenzie, RelativeKind::Cousin]]);

    expect($derived)->not->toHaveKeys(['cousin:Marcus', 'cousin:McKenzie']);
});

it('offers the children of a confirmed uncle as cousins', function () {
    $derived = derivedFrom([[$this->father, RelativeKind::UncleAunt]]);

    expect($derived)->toHaveKeys(['cousin:Marcus', 'cousin:McKenzie']);
});

it('serves derived relatives from the relative suggestions endpoint', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);

    $response = $this->actingAs($encoder)->getJson(route('encoder.relative-suggestions', [
        'relatives' => [['id' => $this->marcus->id, 'relation' => 'cousin']],
    ]))->assertOk();

    $found = collect($response->json('data.relatives'))->map(fn (array $match) => "{$match['relation']}:".explode(' ', $match['resident']['full_name'])[0]);

    expect($found)->toContain('uncle_aunt:Jennifer', 'uncle_aunt:Mark');
});

it('also offers someone suggested as the mother as a possible aunt', function () {
    $person = [
        'first_name' => 'Rica', 'middle_name' => 'Mallari', 'last_name' => 'Torres', 'birthdate' => '2005-02-02',
        'gender' => 'Female', 'civil_status' => 'Single', 'barangay_id' => $this->barangay->id,
    ];

    $matches = app(FindFamilyMatches::class)->handle($person);

    expect(collect($matches['mother'])->pluck('resident.first_name'))->toContain('Jennifer')
        ->and(collect($matches['relatives'])->contains(fn (array $match) => $match['relation'] === 'uncle_aunt' && $match['resident']->is($this->mother)))->toBeTrue();
});
