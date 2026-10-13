<?php

use App\Actions\Residents\FindFamilyMatches;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['code' => 'SJ']);
    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->finder = app(FindFamilyMatches::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function inLawResident(array $attributes = []): Resident
{
    return Resident::factory()->create([
        'household_id' => test()->household->id,
        'barangay_id' => test()->barangay->id,
        'middle_name' => null,
        ...$attributes,
    ]);
}

it('does not match a married woman to her husband\'s surname family as parents', function () {
    inLawResident(['first_name' => 'Pedro', 'last_name' => 'Reyes', 'gender' => 'Male', 'birthdate' => '1955-01-01']);
    inLawResident(['first_name' => 'Rosa', 'last_name' => 'Reyes', 'gender' => 'Female', 'birthdate' => '1960-01-01']);

    $matches = $this->finder->handle([
        'first_name' => 'Ana', 'last_name' => 'Reyes', 'birthdate' => '1987-06-10', 'gender' => 'Female',
        'civil_status' => 'Married', 'barangay_id' => $this->barangay->id,
    ]);

    expect($matches['father'])->toBe([])->and($matches['mother'])->toBe([]);
});

it('uses the maiden name to find the parents of a married woman', function () {
    $father = inLawResident(['first_name' => 'Jose', 'last_name' => 'Santos', 'gender' => 'Male', 'birthdate' => '1958-01-01']);
    inLawResident(['first_name' => 'Pedro', 'last_name' => 'Reyes', 'gender' => 'Male', 'birthdate' => '1955-01-01']);

    $matches = $this->finder->handle([
        'first_name' => 'Ana', 'last_name' => 'Reyes', 'maiden_name' => 'Santos', 'birthdate' => '1987-06-10', 'gender' => 'Female',
        'civil_status' => 'Married', 'barangay_id' => $this->barangay->id,
    ])['father'];

    expect($matches)->toHaveCount(1)->and($matches[0]['resident']->is($father))->toBeTrue();
});

it('never suggests the parents of the spouse as the person\'s own parents', function () {
    $husbandsFather = inLawResident(['first_name' => 'Pedro', 'last_name' => 'Reyes', 'gender' => 'Male', 'birthdate' => '1955-01-01']);
    $husband = inLawResident(['first_name' => 'Luis', 'last_name' => 'Reyes', 'gender' => 'Male', 'birthdate' => '1985-02-02', 'father_id' => $husbandsFather->id]);
    $wife = inLawResident(['first_name' => 'Ana', 'last_name' => 'Reyes', 'gender' => 'Female', 'birthdate' => '1987-06-10', 'civil_status' => 'Single', 'spouse_id' => $husband->id]);

    $matches = $this->finder->handle([
        'first_name' => 'Ana', 'last_name' => 'Reyes', 'birthdate' => '1987-06-10', 'gender' => 'Female', 'civil_status' => 'Single',
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
    ], null, $wife)['father'];

    expect($matches)->toBe([]);
});

it('does not suggest a spouse for someone who already has one', function () {
    $husband = inLawResident(['first_name' => 'Luis', 'gender' => 'Male', 'birthdate' => '1985-02-02']);
    $wife = inLawResident(['first_name' => 'Ana', 'gender' => 'Female', 'birthdate' => '1987-06-10', 'spouse_id' => $husband->id]);
    inLawResident(['first_name' => 'Other', 'gender' => 'Male', 'birthdate' => '1986-01-01']);

    $matches = $this->finder->handle([
        'first_name' => 'Ana', 'last_name' => 'Reyes', 'birthdate' => '1987-06-10', 'gender' => 'Female',
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
    ], null, $wife)['spouse'];

    expect($matches)->toBe([]);
});
