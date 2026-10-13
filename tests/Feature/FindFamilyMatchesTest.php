<?php

use App\Actions\Residents\FindFamilyMatches;
use App\Enums\ResidentStatus;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['code' => 'SJ']);
    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->finder = app(FindFamilyMatches::class);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function matchPerson(array $overrides = []): array
{
    return [
        'first_name' => 'Pedro',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'birthdate' => '2008-05-01',
        'gender' => 'Male',
        'household_id' => null,
        'barangay_id' => test()->barangay->id,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeResident(array $attributes = []): Resident
{
    return Resident::factory()->create([
        'household_id' => test()->household->id,
        'barangay_id' => test()->barangay->id,
        'middle_name' => null,
        'last_name' => 'Dela Cruz',
        ...$attributes,
    ]);
}

it('normalises names so spacing, case and accents do not matter', function (string $a, string $b) {
    expect(Resident::normalizeName($a))->toBe(Resident::normalizeName($b));
})->with([
    ['Dela Cruz', 'DE LA CRUZ'],
    ['Dela Cruz', 'delacruz'],
    ['De Los Santos', 'Delos Santos'],
    ['Niño', 'Nino'],
    ["D'Souza", 'dsouza'],
]);

it('keeps the name keys in sync when a resident is saved', function () {
    $resident = makeResident(['first_name' => 'María', 'middle_name' => 'De la Rosa', 'last_name' => 'Dela Cruz', 'maiden_name' => 'Santos']);

    expect($resident->first_name_key)->toBe('maria')
        ->and($resident->middle_name_key)->toBe('delarosa')
        ->and($resident->last_name_key)->toBe('delacruz')
        ->and($resident->maiden_name_key)->toBe('santos');

    $resident->update(['last_name' => 'Reyes']);

    expect($resident->fresh()->last_name_key)->toBe('reyes');
});

it('suggests a father by surname, age and barangay with high confidence', function () {
    $father = makeResident(['first_name' => 'Juan', 'gender' => 'Male', 'birthdate' => '1980-03-14']);
    makeResident(['first_name' => 'Karl', 'gender' => 'Male', 'birthdate' => '1980-03-14', 'last_name' => 'Reyes']);

    $matches = $this->finder->handle(matchPerson())['father'];

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['resident']->is($father))->toBeTrue()
        ->and($matches[0]['confidence'])->toBe('high')
        ->and($matches[0]['reasons'])->toContain('Same surname', 'Same barangay');
});

it('ignores men who are too young to be the father', function () {
    makeResident(['gender' => 'Male', 'birthdate' => '1998-01-01']);

    expect($this->finder->handle(matchPerson())['father'])->toBe([]);
});

it('suggests a household member with a different surname at medium confidence', function () {
    $stepfather = makeResident(['gender' => 'Male', 'birthdate' => '1978-01-01', 'last_name' => 'Garcia']);

    $matches = $this->finder->handle(matchPerson(['household_id' => $this->household->id]))['father'];

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['resident']->is($stepfather))->toBeTrue()
        ->and($matches[0]['confidence'])->toBe('medium')
        ->and($matches[0]['reasons'])->toContain('Lives in the household you chose');
});

it('suggests the mother through the father and the maiden name rule', function () {
    $father = makeResident(['first_name' => 'Juan', 'gender' => 'Male', 'birthdate' => '1980-03-14']);
    $mother = makeResident(['first_name' => 'Maria', 'gender' => 'Female', 'birthdate' => '1983-07-02', 'maiden_name' => 'Santos', 'spouse_id' => $father->id]);
    $father->update(['spouse_id' => $mother->id]);
    makeResident(['first_name' => 'Ana', 'gender' => 'Female', 'birthdate' => '1984-01-01', 'last_name' => 'Reyes']);

    $matches = $this->finder->handle(matchPerson())['mother'];

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['resident']->is($mother))->toBeTrue()
        ->and($matches[0]['confidence'])->toBe('high')
        ->and($matches[0]['reasons'])->toContain('Spouse of the suggested father', 'Her maiden name matches your middle name');
});

it('suggests an unlinked spouse who lives in the same household', function () {
    $spouse = makeResident(['first_name' => 'Maria', 'gender' => 'Female', 'birthdate' => '1983-07-02', 'civil_status' => 'Married']);
    makeResident(['gender' => 'Female', 'birthdate' => '1983-07-02', 'spouse_id' => $spouse->id]);

    $matches = $this->finder->handle(matchPerson([
        'first_name' => 'Juan', 'birthdate' => '1980-03-14', 'household_id' => $this->household->id,
    ]))['spouse'];

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['resident']->is($spouse))->toBeTrue()
        ->and($matches[0]['confidence'])->toBe('high');
});

it('suggests children without a recorded father who carry the surname', function () {
    $child = makeResident(['first_name' => 'Pedro', 'middle_name' => 'Santos', 'gender' => 'Male', 'birthdate' => '2008-05-01']);
    makeResident(['first_name' => 'Linked', 'birthdate' => '2009-05-01', 'father_id' => $child->id]);
    makeResident(['first_name' => 'Same', 'birthdate' => '1981-05-01']);

    $matches = $this->finder->handle(matchPerson([
        'first_name' => 'Juan', 'birthdate' => '1980-03-14', 'household_id' => $this->household->id,
    ]))['children'];

    expect(collect($matches)->pluck('resident.id')->all())->toBe([$child->id]);
});

it('uses the maiden name to suggest children of a mother', function () {
    $child = makeResident(['first_name' => 'Pedro', 'middle_name' => 'Santos', 'gender' => 'Male', 'birthdate' => '2008-05-01']);

    $matches = $this->finder->handle(matchPerson([
        'first_name' => 'Maria', 'middle_name' => null, 'maiden_name' => 'Santos', 'gender' => 'Female', 'birthdate' => '1983-07-02',
    ]))['children'];

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['resident']->is($child))->toBeTrue()
        ->and($matches[0]['reasons'])->toContain('Your maiden name is their middle name');
});

it('limits candidates to a barangay but keeps relatives listed from it', function () {
    $other = Barangay::factory()->create();
    $otherHousehold = Household::factory()->create(['barangay_id' => $other->id]);
    makeResident(['gender' => 'Male', 'birthdate' => '1980-01-01', 'household_id' => $otherHousehold->id, 'barangay_id' => $other->id]);

    $lister = makeResident(['first_name' => 'Lister', 'gender' => 'Female', 'birthdate' => '2001-01-01']);
    $listed = Resident::factory()->listed()->create([
        'first_name' => 'Listed', 'last_name' => 'Dela Cruz', 'gender' => 'Male', 'birthdate' => '1979-01-01', 'listed_by_resident_id' => $lister->id,
    ]);

    $scoped = $this->finder->handle(matchPerson(), $this->barangay->id)['father'];
    $everywhere = $this->finder->handle(matchPerson())['father'];

    expect(collect($scoped)->pluck('resident.id')->all())->toBe([$listed->id])
        ->and($everywhere)->toHaveCount(2);
});

it('never suggests the subject or archived residents', function () {
    $subject = makeResident(['gender' => 'Male', 'birthdate' => '1980-01-01']);
    makeResident(['gender' => 'Male', 'birthdate' => '1981-01-01', 'status' => ResidentStatus::Archived]);

    $matches = $this->finder->handle(matchPerson(), null, $subject)['father'];

    expect($matches)->toBe([]);
});

it('offers listed records the person may claim, best match first', function () {
    $exact = Resident::factory()->listed()->create(['first_name' => 'Maria', 'last_name' => 'Dela Cruz', 'gender' => 'Female', 'birthdate' => '1983-07-02']);
    $nearby = Resident::factory()->listed()->create(['first_name' => 'Maria', 'last_name' => 'Dela Cruz', 'gender' => 'Female', 'birthdate' => '1983-07-05']);
    Resident::factory()->listed()->create(['first_name' => 'Maria', 'last_name' => 'Dela Cruz', 'gender' => 'Female', 'birthdate' => '1983-07-02', 'is_deceased' => true]);
    Resident::factory()->listed()->create(['first_name' => 'Maria', 'last_name' => 'Dela Cruz', 'gender' => 'Male', 'birthdate' => '1983-07-02']);
    Resident::factory()->create(['first_name' => 'Maria', 'last_name' => 'Dela Cruz', 'gender' => 'Female', 'birthdate' => '1983-07-02']);

    $claims = $this->finder->handle(matchPerson(['first_name' => 'maria', 'last_name' => 'DELACRUZ', 'birthdate' => '1983-07-02', 'gender' => 'Female']))['claims'];

    expect($claims)->toHaveCount(2)
        ->and($claims[0]['resident']->is($exact))->toBeTrue()
        ->and($claims[0]['reasons'])->toBe(['Same name', 'Same birthdate'])
        ->and($claims[1]['resident']->is($nearby))->toBeTrue()
        ->and($claims[1]['reasons'])->toBe(['Same name', 'Similar birthdate']);
});

it('still offers a listed record when the birthdate differs, with a warning', function () {
    $listed = Resident::factory()->listed()->create(['first_name' => 'Mark', 'last_name' => 'Cariño', 'gender' => 'Male', 'birthdate' => '1985-10-10']);

    $claims = $this->finder->handle(matchPerson(['first_name' => 'Mark', 'last_name' => 'Carino', 'birthdate' => '1990-01-01', 'gender' => 'Male']))['claims'];

    expect($claims)->toHaveCount(1)
        ->and($claims[0]['resident']->is($listed))->toBeTrue()
        ->and($claims[0]['reasons'])->toContain('Different birthdate, please check with your family');
});

it('matches similar first names such as a first name typed with the middle name', function (string $typed) {
    $listed = Resident::factory()->listed()->create(['first_name' => 'Mark', 'last_name' => 'Cariño', 'gender' => 'Male', 'birthdate' => '1985-10-10']);

    $claims = $this->finder->handle(matchPerson(['first_name' => $typed, 'last_name' => 'Cariño', 'birthdate' => '1985-10-10', 'gender' => 'Male']))['claims'];

    expect($claims)->toHaveCount(1)->and($claims[0]['resident']->is($listed))->toBeTrue();
})->with(['Mark Flores', 'Marc', 'MARK']);

it('does not offer a different person with a different name and birthdate', function () {
    Resident::factory()->listed()->create(['first_name' => 'Mark', 'last_name' => 'Cariño', 'gender' => 'Male', 'birthdate' => '1985-10-10']);

    $claims = $this->finder->handle(matchPerson(['first_name' => 'Luis', 'last_name' => 'Cariño', 'birthdate' => '1985-10-10', 'gender' => 'Male']))['claims'];

    expect($claims)->toBe([]);
});
it('only returns claims when no gender is known and nothing else', function () {
    makeResident(['gender' => 'Male', 'birthdate' => '1980-01-01']);

    $result = $this->finder->handle(matchPerson(['gender' => null]));

    expect($result['father'])->toBe([])->and($result['claims'])->toBe([]);
});
