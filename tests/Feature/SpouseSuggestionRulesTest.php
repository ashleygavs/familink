<?php

use App\Actions\Residents\FindFamilyMatches;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['code' => 'SJ']);
    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->finder = app(FindFamilyMatches::class);

    $this->father = Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'first_name' => 'Mark', 'middle_name' => null, 'last_name' => 'Cariño', 'gender' => 'Male', 'birthdate' => '1984-10-10',
    ]);
    $this->brother = Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'first_name' => 'Marcus', 'middle_name' => null, 'last_name' => 'Cariño', 'gender' => 'Male', 'birthdate' => '2004-01-21',
        'father_id' => $this->father->id, 'civil_status' => 'Single',
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function siblingPerson(array $overrides = []): array
{
    return [
        'first_name' => 'Maria', 'last_name' => 'Cariño', 'birthdate' => '2007-03-03', 'gender' => 'Female',
        'household_id' => test()->household->id, 'barangay_id' => test()->barangay->id, ...$overrides,
    ];
}

it('never suggests a single person\'s brother or sister as their spouse', function () {
    expect($this->finder->handle(siblingPerson(['civil_status' => 'Single']))['spouse'])->toBe([]);
});

it('does not suggest a sibling as a spouse even when the civil status is unknown', function () {
    $result = $this->finder->handle(siblingPerson());

    expect($result['father'][0]['resident']->is($this->father))->toBeTrue()
        ->and($result['spouse'])->toBe([]);
});

it('still suggests a spouse for someone who is married', function () {
    $husband = Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'first_name' => 'Luis', 'last_name' => 'Cariño', 'gender' => 'Male', 'birthdate' => '2000-01-01', 'civil_status' => 'Married',
    ]);

    $spouse = $this->finder->handle(siblingPerson(['civil_status' => 'Married', 'birthdate' => '2001-03-03']))['spouse'];

    expect($spouse)->toHaveCount(1)->and($spouse[0]['resident']->is($husband))->toBeTrue();
});
