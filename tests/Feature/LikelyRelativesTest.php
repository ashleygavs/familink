<?php

use App\Actions\Residents\FindFamilyMatches;
use App\Actions\Residents\FindLikelyRelatives;
use App\Enums\ResidentStatus;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use Illuminate\Support\Carbon;

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
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function relativesPerson(array $overrides = []): array
{
    return [
        'first_name' => 'Maria', 'last_name' => 'Cariño', 'birthdate' => '2007-03-03', 'gender' => 'Female',
        'civil_status' => 'Single', 'barangay_id' => test()->barangay->id, ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $person
 * @return array<string, array<string, mixed>>
 */
function likelyRelativesOf(array $person): array
{
    return collect(app(FindFamilyMatches::class)->handle($person)['relatives'])
        ->groupBy(fn (array $match) => $match['relation'])
        ->map(fn ($group) => $group->keyBy(fn (array $match) => $match['resident']->first_name)->all())
        ->all();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function likelyContext(array $overrides = []): array
{
    return [
        'birth' => Carbon::parse('2007-03-03'), 'parent_surname' => 'carino', 'middle' => null, 'household_id' => null,
        'barangay_id' => test()->barangay->id, 'scope' => null, 'subject' => null, ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $matches
 * @return array<string, array<string, array<string, mixed>>>
 */
function groupedRelatives(array $matches): array
{
    return collect($matches)
        ->groupBy(fn (array $match) => $match['relation'])
        ->map(fn ($group) => $group->keyBy(fn (array $match) => $match['resident']->first_name)->all())
        ->all();
}

it('suggests a registered brother or sister automatically', function () {
    $father = ($this->make)('Mark', 'Male', '1984-10-10');
    $mother = ($this->make)('Jennifer', 'Female', '1984-01-21', ['last_name' => 'Mallari', 'spouse_id' => $father->id]);
    $father->update(['spouse_id' => $mother->id]);
    $marcus = ($this->make)('Marcus', 'Male', '2004-01-21', ['father_id' => $father->id, 'mother_id' => $mother->id]);

    $relatives = array_values(array_filter(app(FindFamilyMatches::class)->handle(relativesPerson())['relatives'], fn (array $match) => $match['relation'] === 'sibling'));

    expect($relatives)->toHaveCount(1)
        ->and($relatives[0]['resident']->is($marcus))->toBeTrue()
        ->and($relatives[0]['relation'])->toBe('sibling')
        ->and($relatives[0]['confidence'])->toBe('high')
        ->and($relatives[0]['reasons'])->toContain('Same surname', 'Similar age');
});

it('offers a possible sibling living elsewhere as a cousin too', function () {
    ($this->make)('Marcus', 'Male', '2004-01-21', ['household_id' => Household::factory()->create()->id]);

    $relatives = likelyRelativesOf(relativesPerson());

    expect($relatives['sibling'])->toHaveKey('Marcus')
        ->and($relatives['cousin']['Marcus']['score'])->toBeLessThan($relatives['sibling']['Marcus']['score']);
});

it('suggests a relative even when their own parents are not on record', function () {
    $marcus = ($this->make)('Marcus', 'Male', '2004-01-21');

    $relatives = app(FindFamilyMatches::class)->handle(relativesPerson())['relatives'];

    expect($relatives)->not->toBe([])->and($relatives[0]['resident']->is($marcus))->toBeTrue()->and($relatives[0]['relation'])->toBe('sibling');
});

it('does not suggest archived residents, other families or the suggested parents themselves', function () {
    $father = ($this->make)('Mark', 'Male', '1984-10-10');
    ($this->make)('Archived', 'Male', '2004-01-21', ['father_id' => $father->id, 'status' => ResidentStatus::Archived]);
    ($this->make)('Stranger', 'Male', '2004-01-21', ['father_id' => $father->id, 'last_name' => 'Santos', 'household_id' => Household::factory()->create()->id, 'barangay_id' => null]);

    $names = collect(app(FindFamilyMatches::class)->handle(relativesPerson())['relatives'])->pluck('resident.first_name')->all();

    expect($names)->not->toContain('Archived', 'Stranger', 'Mark');
});

it('suggests an elderly relative with the same surname as a possible grandparent', function () {
    ($this->make)('Gregorio', 'Male', '1930-01-01');

    expect(likelyRelativesOf(relativesPerson())['grandparent']['Gregorio']['reasons'])->toContain('Same surname');
});

it('suggests a much younger relative as a possible grandchild or nephew', function () {
    ($this->make)('Marcus', 'Male', '2004-01-21');

    $asGrandparent = likelyRelativesOf(relativesPerson(['first_name' => 'Gregorio', 'birthdate' => '1930-01-01', 'gender' => 'Male']));
    $asUncle = likelyRelativesOf(relativesPerson(['first_name' => 'Ben', 'birthdate' => '1975-01-01', 'gender' => 'Male']));

    expect($asGrandparent)->toHaveKey('grandchild')
        ->and($asGrandparent['grandchild'])->toHaveKey('Marcus')
        ->and($asUncle['nephew_niece'])->toHaveKey('Marcus')
        ->and($asUncle['cousin']['Marcus']['score'])->toBeLessThan($asUncle['nephew_niece']['Marcus']['score']);
});

it('suggests a cousin who lives elsewhere and a possible uncle', function () {
    $otherBarangay = Barangay::factory()->create();
    $otherHousehold = Household::factory()->create(['barangay_id' => $otherBarangay->id]);
    ($this->make)('Ben', 'Male', '1963-01-01');
    ($this->make)('Carl', 'Male', '2005-05-05', ['household_id' => $otherHousehold->id, 'barangay_id' => $otherBarangay->id]);

    $relatives = groupedRelatives(app(FindLikelyRelatives::class)->handle(likelyContext(), []));

    expect($relatives['cousin'])->toHaveKey('Carl')
        ->and($relatives['uncle_aunt'])->toHaveKey('Ben');
});

it('finds cousins on the mother\'s side through the middle name', function () {
    ($this->make)('Marcus', 'Male', '2004-01-21', ['middle_name' => 'Mallari']);

    $relatives = likelyRelativesOf(relativesPerson(['last_name' => 'Torres', 'middle_name' => 'Mallari', 'birthdate' => '2001-03-03']));

    expect($relatives)->not->toHaveKey('sibling')
        ->and($relatives['cousin']['Marcus']['reasons'])->toContain("Shares your mother's family name");
});

it('finds cousins when the surname matches the other side\'s family name', function () {
    ($this->make)('Marcus', 'Male', '2004-01-21', ['middle_name' => 'Mallari']);

    $relatives = likelyRelativesOf(relativesPerson(['last_name' => 'Mallari', 'birthdate' => '2001-03-03']));

    expect($relatives['cousin']['Marcus']['reasons'])->toContain("Linked through your parents' family names");
});
it('suggests cousins with a wide age gap', function () {
    ($this->make)('Carl', 'Male', '1990-05-05', ['household_id' => Household::factory()->create()->id, 'barangay_id' => null]);

    $relatives = groupedRelatives(app(FindLikelyRelatives::class)->handle(likelyContext(['birth' => Carbon::parse('2012-03-03')]), []));

    expect($relatives['cousin'])->toHaveKey('Carl');
});
it('suggests brothers or sisters who live in the chosen household even with another surname', function () {
    $father = ($this->make)('Mark', 'Male', '1984-10-10');
    $stepBrother = ($this->make)('Stepbro', 'Male', '2006-02-02', ['last_name' => 'Garcia', 'father_id' => $father->id]);

    $relatives = app(FindFamilyMatches::class)->handle(relativesPerson(['household_id' => $this->household->id]))['relatives'];

    expect(collect($relatives)->pluck('resident.id')->all())->toContain($stepBrother->id);
});

it('limits the suggestions for each kind of relative', function () {
    foreach (range(1, 6) as $number) {
        ($this->make)("Sib{$number}", 'Male', '2006-0'.$number.'-01');
    }

    $siblings = collect(app(FindFamilyMatches::class)->handle(relativesPerson())['relatives'])->where('relation', 'sibling');

    expect($siblings)->toHaveCount(3);
});

it('works from a hand-built context without a middle name', function () {
    ($this->make)('Carl', 'Male', '2005-05-05');

    $context = [
        'birth' => Carbon::parse('2007-03-03'), 'parent_surname' => 'carino', 'middle' => null, 'household_id' => null,
        'barangay_id' => $this->barangay->id, 'scope' => null, 'subject' => null,
    ];

    expect(app(FindLikelyRelatives::class)->handle($context, []))->not->toBe([]);
});

it('does not turn the children of a weakly suggested parent into strong siblings', function () {
    // Gabriel Aquino's mother was a Reyes, so a woman named Reyes is only a weak guess for his mother.
    $ana = ($this->make)('Ana', 'Female', '1987-06-10', ['last_name' => 'Reyes']);
    ($this->make)('Miguel', 'Male', '2012-09-09', ['last_name' => 'Reyes', 'mother_id' => $ana->id]);
    ($this->make)('Rico', 'Male', '2015-03-03', ['last_name' => 'Reyes', 'mother_id' => $ana->id]);

    $person = relativesPerson(['first_name' => 'Gabriel', 'middle_name' => 'Reyes', 'last_name' => 'Aquino', 'birthdate' => '2010-02-14', 'gender' => 'Male']);
    $matches = app(FindFamilyMatches::class)->handle($person);
    $relatives = collect($matches['relatives'])->groupBy('relation')->map(fn ($group) => $group->keyBy(fn (array $match) => $match['resident']->first_name));

    expect($matches['mother'][0]['confidence'])->toBe('medium')
        ->and($relatives->has('sibling'))->toBeFalse()
        ->and($relatives['cousin']['Miguel']['confidence'])->toBe('medium')
        ->and($relatives['cousin']['Miguel']['score'])->toBeLessThan(70);
});

it('never calls a match strong when the only link is a shared family name', function () {
    // Luis and his mother are both Reyes, which matches Gabriel's middle name twice over. That is still one fact.
    $lourdes = ($this->make)('Lourdes', 'Female', '1960-01-01', ['last_name' => 'Reyes']);
    ($this->make)('Luis', 'Male', '1985-02-02', ['last_name' => 'Reyes', 'mother_id' => $lourdes->id]);

    $person = relativesPerson(['first_name' => 'Gabriel', 'middle_name' => 'Reyes', 'last_name' => 'Aquino', 'birthdate' => '2010-02-14', 'gender' => 'Male']);
    $matches = collect(app(FindFamilyMatches::class)->handle($person)['relatives'])->where('resident.first_name', 'Luis');

    expect($matches)->not->toBeEmpty()
        ->and($matches->pluck('confidence')->unique()->all())->toBe(['medium']);
});

it('still calls a match strong when it also shares the surname, the household or a strong parent', function () {
    $father = ($this->make)('Mark', 'Male', '1984-10-10', ['last_name' => 'Aquino']);
    ($this->make)('Marcus', 'Male', '2004-01-21', ['last_name' => 'Aquino', 'father_id' => $father->id]);
    ($this->make)('Reyesdweller', 'Male', '1985-02-02', ['last_name' => 'Reyes']);

    $person = relativesPerson(['first_name' => 'Gabriel', 'middle_name' => 'Reyes', 'last_name' => 'Aquino', 'birthdate' => '2010-02-14', 'gender' => 'Male', 'household_id' => $this->household->id]);
    $relatives = collect(app(FindFamilyMatches::class)->handle($person)['relatives']);

    // Same surname and a strongly suggested father: strong. Same household: strong even with only a family-name link.
    expect($relatives->where('resident.first_name', 'Marcus')->pluck('confidence')->contains('high'))->toBeTrue()
        ->and($relatives->where('resident.first_name', 'Reyesdweller')->pluck('confidence')->contains('high'))->toBeTrue();
});
