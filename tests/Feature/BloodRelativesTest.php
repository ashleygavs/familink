<?php

use App\Actions\Residents\FindBloodRelatives;
use App\Actions\Residents\FindFamilyMatches;
use App\Actions\Residents\FindParentsThroughRelative;
use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;

/**
 * A three-generation family:
 * Grandfather + Grandmother -> Mark, Ben and Ann.
 * Mark + Jennifer -> Marcus. Ben -> Carl (Marcus's cousin).
 */
beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['code' => 'SJ']);
    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);

    $make = fn (string $first, string $gender, string $birthdate, array $extra = []) => Resident::factory()->create([
        'household_id' => $this->household->id,
        'barangay_id' => $this->barangay->id,
        'first_name' => $first,
        'middle_name' => null,
        'last_name' => 'Cariño',
        'gender' => $gender,
        'birthdate' => $birthdate,
        ...$extra,
    ]);

    $this->grandfather = $make('Gregorio', 'Male', '1930-01-01');
    $this->grandmother = $make('Gloria', 'Female', '1932-01-01', ['last_name' => 'Reyes']);
    $parents = ['father_id' => $this->grandfather->id, 'mother_id' => $this->grandmother->id];
    $this->mark = $make('Mark', 'Male', '1960-01-01', $parents);
    $this->ben = $make('Ben', 'Male', '1963-01-01', $parents);
    $this->ann = $make('Ann', 'Female', '1965-01-01', $parents);
    $this->jennifer = $make('Jennifer', 'Female', '1962-01-01', ['last_name' => 'Mallari', 'spouse_id' => $this->mark->id]);
    $this->mark->update(['spouse_id' => $this->jennifer->id]);
    $this->marcus = $make('Marcus', 'Male', '2004-01-21', ['father_id' => $this->mark->id, 'mother_id' => $this->jennifer->id]);
    $this->carl = $make('Carl', 'Male', '2005-05-05', ['father_id' => $this->ben->id]);
});

it('works out siblings, grandparents, uncles, aunts and cousins from the parents', function () {
    $relatives = app(FindBloodRelatives::class)->handle($this->mark->id, $this->jennifer->id);

    $labelled = fn (array $group) => collect($group)->mapWithKeys(fn (array $r) => [$r['resident']->first_name => $r['relation']])->all();

    expect($labelled($relatives['siblings']))->toBe(['Marcus' => 'Brother'])
        ->and($labelled($relatives['uncles_aunts']))->toBe(['Ben' => "Uncle (father's side)", 'Ann' => "Aunt (father's side)"])
        ->and($labelled($relatives['cousins']))->toBe(['Carl' => 'Cousin'])
        ->and($labelled($relatives['grandparents']))->toBe([
            'Gregorio' => "Grandfather (father's side)",
            'Gloria' => "Grandmother (father's side)",
        ]);
});

it('marks half siblings and leaves the person themselves out', function () {
    $half = Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'first_name' => 'Hana', 'gender' => 'Female', 'birthdate' => '2000-01-01', 'father_id' => $this->mark->id,
    ]);

    $relatives = app(FindBloodRelatives::class)->handle($this->mark->id, $this->jennifer->id, $this->marcus->id);

    expect(collect($relatives['siblings'])->pluck('relation', 'resident.id')->all())->toBe([$half->id => 'Half-sister']);
});

it('finds nothing without parents and ignores archived relatives', function () {
    $this->marcus->update(['status' => ResidentStatus::Archived]);

    expect(app(FindBloodRelatives::class)->handle(null, null)['siblings'])->toBe([])
        ->and(app(FindBloodRelatives::class)->handle($this->mark->id, $this->jennifer->id)['siblings'])->toBe([]);
});

it('suggests the parents of a person who is the brother or sister of a registered resident', function () {
    $parents = app(FindParentsThroughRelative::class)->handle($this->marcus, 'sibling');

    expect($parents['father'][0]['resident']->is($this->mark))->toBeTrue()
        ->and($parents['father'][0]['confidence'])->toBe('high')
        ->and($parents['mother'][0]['resident']->is($this->jennifer))->toBeTrue()
        ->and($parents['mother'][0]['reasons'][0])->toContain('Marcus');
});

it('suggests parents through a grandparent, an uncle or aunt, or a cousin', function () {
    $finder = app(FindParentsThroughRelative::class);
    $names = fn (array $matches) => collect($matches)->pluck('resident.first_name')->sort()->values()->all();

    $viaGrandparent = $finder->handle($this->grandfather, 'grandparent');
    $viaUncle = $finder->handle($this->ben, 'uncle_aunt');
    $viaCousin = $finder->handle($this->carl, 'cousin');

    expect($names($viaGrandparent['father']))->toBe(['Ben', 'Mark'])
        ->and($names($viaGrandparent['mother']))->toBe(['Ann'])
        ->and($names($viaUncle['father']))->toBe(['Mark'])
        ->and($names($viaUncle['mother']))->toBe(['Ann'])
        ->and($names($viaCousin['father']))->toBe(['Mark'])
        ->and($viaCousin['father'][0]['confidence'])->toBe('medium');
});

it('suggests the parents of residents who share the surname and a similar age', function () {
    $matches = app(FindFamilyMatches::class)->handle([
        'first_name' => 'Mia', 'last_name' => 'Cariño', 'birthdate' => '2007-03-03', 'gender' => 'Female', 'barangay_id' => $this->barangay->id,
    ]);

    expect($matches['father'][0]['resident']->is($this->mark))->toBeTrue()
        ->and($matches['father'][0]['reasons'])->toContain('Father of Marcus, who has your surname and a similar age')
        ->and($matches['mother'][0]['resident']->is($this->jennifer))->toBeTrue()
        ->and($matches['mother'][0]['confidence'])->toBe('high');
});

it('serves blood relatives and relative suggestions to encoders only', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);

    $this->actingAs($encoder)
        ->getJson(route('encoder.family-relatives', ['father_id' => $this->mark->id, 'mother_id' => $this->jennifer->id]))
        ->assertOk()
        ->assertJsonPath('data.siblings.0.resident.id', $this->marcus->id)
        ->assertJsonPath('data.siblings.0.relation', 'Brother')
        ->assertJsonPath('data.cousins.0.resident.full_name', 'Carl Cariño')
        ->assertJsonStructure(['data' => ['siblings', 'grandparents', 'uncles_aunts', 'cousins']]);

    $this->actingAs($encoder)
        ->getJson(route('encoder.relative-suggestions', ['relatives' => [['id' => $this->marcus->id, 'relation' => 'sibling']]]))
        ->assertOk()
        ->assertJsonPath('data.father.0.resident.id', $this->mark->id)
        ->assertJsonPath('data.mother.0.resident.id', $this->jennifer->id);

    $this->actingAs(User::factory()->withRole(RoleName::Stakeholder)->create())
        ->getJson(route('encoder.family-relatives'))
        ->assertForbidden();
});

it('validates the relative and relation', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);
    $this->marcus->update(['status' => ResidentStatus::Archived]);

    $this->actingAs($encoder)->getJson(route('encoder.relative-suggestions', ['relatives' => [['id' => $this->marcus->id, 'relation' => 'sibling']]]))->assertUnprocessable();
    $this->actingAs($encoder)->getJson(route('encoder.relative-suggestions', ['relatives' => [['id' => $this->mark->id, 'relation' => 'neighbour']]]))->assertUnprocessable();
    $this->actingAs($encoder)->getJson(route('encoder.family-relatives', ['father_id' => 999999]))->assertUnprocessable();
});

it('combines several relatives and ranks a parent named by more than one higher', function () {
    $finder = app(FindParentsThroughRelative::class);

    $single = $finder->handle($this->ben, 'uncle_aunt');
    $merged = $finder->handleMany([
        ['relative' => $this->ben, 'relation' => 'uncle_aunt'],
        ['relative' => $this->carl, 'relation' => 'cousin'],
    ]);

    expect($single['father'][0]['score'])->toBe(60)
        ->and($merged['father'][0]['resident']->is($this->mark))->toBeTrue()
        ->and($merged['father'][0]['score'])->toBe(75)
        ->and($merged['father'][0]['confidence'])->toBe('high')
        ->and($merged['father'][0]['reasons'])->toHaveCount(2);
});

it('accepts several relatives in one request and rejects an empty list', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);

    $this->actingAs($encoder)
        ->getJson(route('encoder.relative-suggestions', ['relatives' => [
            ['id' => $this->ben->id, 'relation' => 'uncle_aunt'],
            ['id' => $this->carl->id, 'relation' => 'cousin'],
        ]]))
        ->assertOk()
        ->assertJsonPath('data.father.0.resident.id', $this->mark->id)
        ->assertJsonPath('data.father.0.confidence', 'high');

    $this->actingAs($encoder)->getJson(route('encoder.relative-suggestions'))->assertUnprocessable();
});
