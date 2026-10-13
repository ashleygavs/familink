<?php

use App\Actions\Residents\FindFamilyMatches;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['code' => 'SJ']);
    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->otherBarangay = Barangay::factory()->create(['code' => 'XX']);
    $this->otherHousehold = Household::factory()->create(['barangay_id' => $this->otherBarangay->id]);
    $this->finder = app(FindFamilyMatches::class);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scaledPerson(array $overrides = []): array
{
    return [
        'first_name' => 'Mia', 'last_name' => 'Cariño', 'birthdate' => '2007-03-03', 'gender' => 'Female',
        'civil_status' => 'Single', 'barangay_id' => test()->barangay->id, ...$overrides,
    ];
}

it('still finds the real father and brother among hundreds of unrelated residents with the same surname', function () {
    Resident::factory()->count(350)->create([
        'household_id' => $this->otherHousehold->id, 'barangay_id' => $this->otherBarangay->id,
        'last_name' => 'Cariño', 'middle_name' => null, 'gender' => 'Male', 'birthdate' => '1980-01-01',
    ]);

    $father = Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'first_name' => 'Mark', 'last_name' => 'Cariño', 'middle_name' => null, 'gender' => 'Male', 'birthdate' => '1984-10-10',
    ]);
    $brother = Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'first_name' => 'Marcus', 'last_name' => 'Cariño', 'middle_name' => null, 'gender' => 'Male', 'birthdate' => '2004-01-21',
        'father_id' => $father->id,
    ]);

    $matches = $this->finder->handle(scaledPerson());

    expect($matches['father'][0]['resident']->is($father))->toBeTrue()
        ->and($matches['father'][0]['reasons'])->toContain('Same barangay')
        ->and(collect($matches['relatives'])->firstWhere('relation', 'sibling')['resident']->is($brother))->toBeTrue();
});

it('only scores a bounded number of candidates however many residents match', function () {
    Resident::factory()->count(320)->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'last_name' => 'Cariño', 'middle_name' => null, 'gender' => 'Male', 'birthdate' => '1980-01-01',
    ]);

    DB::enableQueryLog();
    $matches = $this->finder->handle(scaledPerson());
    $queries = count(DB::getQueryLog());

    expect($matches['father'])->toHaveCount(3)
        ->and($queries)->toBeLessThan(60);
});

it('returns the requested number of matches per role', function () {
    foreach (range(1, 6) as $number) {
        Resident::factory()->create([
            'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
            'first_name' => "Dad{$number}", 'last_name' => 'Cariño', 'middle_name' => null, 'gender' => 'Male', 'birthdate' => '198'.$number.'-01-01',
        ]);
    }

    expect($this->finder->handle(scaledPerson())['father'])->toHaveCount(3)
        ->and($this->finder->handle(scaledPerson(), null, null, ['limit' => 5])['father'])->toHaveCount(5)
        ->and($this->finder->handle(scaledPerson(), null, null, ['limit' => 99])['father'])->toHaveCount(6);
});

it('skips the people the person already said no to and offers the next best', function () {
    $dads = collect(range(1, 4))->map(fn (int $number) => Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'first_name' => "Dad{$number}", 'last_name' => 'Cariño', 'middle_name' => null, 'gender' => 'Male', 'birthdate' => '198'.$number.'-01-01',
    ]));

    $first = $this->finder->handle(scaledPerson(), null, null, ['limit' => 2])['father'];
    $rejected = array_map(fn (array $match) => $match['resident']->id, $first);
    $next = $this->finder->handle(scaledPerson(), null, null, ['limit' => 2, 'rejected' => ['father' => $rejected]])['father'];

    expect(array_intersect($rejected, array_map(fn (array $match) => $match['resident']->id, $next)))->toBe([])
        ->and($next)->toHaveCount(2)
        ->and($dads)->toHaveCount(4);
});

it('remembers rejections per role and per kind of relative', function () {
    $marcus = Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'first_name' => 'Marcus', 'last_name' => 'Cariño', 'middle_name' => null, 'gender' => 'Male', 'birthdate' => '2004-01-21',
    ]);

    $asSibling = fn (array $rejected) => collect($this->finder->handle(scaledPerson(), null, null, ['rejected' => $rejected])['relatives'])->where('relation', 'sibling');
    $asCousin = fn (array $rejected) => collect($this->finder->handle(scaledPerson(), null, null, ['rejected' => $rejected])['relatives'])->where('relation', 'cousin');

    expect($asSibling([]))->toHaveCount(1)
        ->and($asSibling(['relatives' => ['cousin' => [$marcus->id]]]))->toHaveCount(1)
        ->and($asSibling(['relatives' => ['sibling' => [$marcus->id]]]))->toHaveCount(0)
        ->and($asCousin(['relatives' => ['sibling' => [$marcus->id]]]))->toHaveCount(1)
        ->and($asCousin(['relatives' => ['cousin' => [$marcus->id]]]))->toHaveCount(0);
});

it('accepts the limit and rejected lists on the suggestions endpoint', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);
    $dads = collect(range(1, 4))->map(fn (int $number) => Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'first_name' => "Dad{$number}", 'last_name' => 'Cariño', 'middle_name' => null, 'gender' => 'Male', 'birthdate' => '198'.$number.'-01-01',
    ]));
    $query = ['first_name' => 'Mia', 'last_name' => 'Cariño', 'birthdate' => '2007-03-03', 'gender' => 'Female'];

    $first = $this->actingAs($encoder)->getJson(route('encoder.family-suggestions', [...$query, 'limit' => 2]))
        ->assertOk()->assertJsonCount(2, 'data.father')->json('data.father.*.resident.id');

    $this->actingAs($encoder)->getJson(route('encoder.family-suggestions', [...$query, 'limit' => 4, 'rejected' => ['father' => [$first[0]]]]))
        ->assertOk()
        ->assertJsonCount(3, 'data.father');

    expect($dads)->toHaveCount(4);
});

it('rejects an invalid limit', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);

    $this->actingAs($encoder)->getJson(route('encoder.family-suggestions', [
        'first_name' => 'Mia', 'last_name' => 'Cariño', 'birthdate' => '2007-03-03', 'limit' => 13,
    ]))->assertUnprocessable();
});
