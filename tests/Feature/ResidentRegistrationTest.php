<?php

use App\Enums\HouseholdRole;
use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['name' => 'San Jose', 'code' => 'SJ']);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationPayload(array $overrides = []): array
{
    return [
        'first_name' => 'maria',
        'middle_name' => 'santos',
        'last_name' => 'dela cruz',
        'birthdate' => '1990-05-20',
        'gender' => 'Female',
        'civil_status' => 'Single',
        'household_mode' => 'new',
        'street_address' => '12 Rizal Street',
        'is_head' => false,
        'household_role' => 'Other',
        ...$overrides,
    ];
}

it('registers a pending resident in a new household with generated identifiers', function () {
    $this->actingAs($this->encoder)
        ->post(route('encoder.register.store'), registrationPayload())
        ->assertRedirect(route('encoder.register'))
        ->assertSessionHas('registered', fn (array $registered) => $registered['full_name'] === 'Maria Santos Dela Cruz'
            && strlen($registered['qr_code_hash']) === 64);

    $resident = Resident::query()->firstOrFail();

    expect($resident->status)->toBe(ResidentStatus::Pending)
        ->and($resident->system_id_no)->toBe('FL-'.now()->year.'-SJ-00001')
        ->and($resident->household->household_code)->toBe('HH-SJ-0001')
        ->and($resident->barangay_id)->toBe($this->barangay->id)
        ->and($resident->household->head_resident_id)->toBeNull()
        ->and($resident->household_role)->toBe('Other');
});

it('increments identifier sequences', function () {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload());
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload(['first_name' => 'Ana']));

    expect(Resident::query()->orderBy('id')->pluck('system_id_no')->all())->toBe([
        'FL-'.now()->year.'-SJ-00001',
        'FL-'.now()->year.'-SJ-00002',
    ])->and(Household::query()->orderBy('id')->pluck('household_code')->all())->toBe(['HH-SJ-0001', 'HH-SJ-0002']);
});

it('makes the resident head only when the checkbox is selected', function () {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload(['is_head' => true, 'household_role' => null]))
        ->assertSessionHasNoErrors();

    $resident = Resident::query()->firstOrFail();

    expect($resident->household->head_resident_id)->toBe($resident->id)
        ->and($resident->household_role)->toBe(HouseholdRole::Head->value);
});

it('lets a child join a household that has no head and the head join later', function () {
    $household = Household::factory()->create(['barangay_id' => $this->barangay->id]);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload([
        'household_mode' => 'existing', 'household_id' => $household->id, 'household_role' => 'Child',
    ]))->assertSessionHasNoErrors();

    expect($household->fresh()->head_resident_id)->toBeNull();

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload([
        'first_name' => 'Pedro', 'gender' => 'Male', 'birthdate' => '1965-01-01',
        'household_mode' => 'existing', 'household_id' => $household->id, 'is_head' => true, 'household_role' => null,
    ]))->assertSessionHasNoErrors();

    expect($household->fresh()->head->first_name)->toBe('Pedro');
});

it('rejects a second head for the same household', function () {
    $head = Resident::factory()->create();
    $head->household->update(['head_resident_id' => $head->id, 'barangay_id' => $this->barangay->id]);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload([
        'household_mode' => 'existing', 'household_id' => $head->household_id, 'is_head' => true, 'household_role' => null,
    ]))->assertSessionHasErrors('is_head');
});

it('always registers encoders in their own barangay and rejects households from other barangays', function () {
    $other = Barangay::factory()->create();
    $foreignHousehold = Household::factory()->create(['barangay_id' => $other->id]);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload(['barangay_id' => $other->id]));

    expect(Resident::query()->firstOrFail()->barangay_id)->toBe($this->barangay->id);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload([
        'first_name' => 'Ana', 'household_mode' => 'existing', 'household_id' => $foreignHousehold->id,
    ]))->assertSessionHasErrors('household_id');
});

it('requires a barangay when the user has none', function () {
    $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create();

    $this->actingAs($superAdmin)->post(route('encoder.register.store'), registrationPayload())
        ->assertSessionHasErrors('barangay_id');

    $this->actingAs($superAdmin)->post(route('encoder.register.store'), registrationPayload(['barangay_id' => $this->barangay->id]))
        ->assertSessionHasNoErrors();
});

it('links the father and mother and validates their gender and age', function () {
    $father = Resident::factory()->create(['gender' => 'Male', 'birthdate' => '1960-01-01']);
    $mother = Resident::factory()->create(['gender' => 'Female', 'birthdate' => '1962-01-01']);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload([
        'father_id' => $father->id, 'mother_id' => $mother->id,
    ]))->assertSessionHasNoErrors();

    $child = Resident::query()->where('first_name', 'Maria')->firstOrFail();
    expect($child->father->is($father))->toBeTrue()->and($child->mother->is($mother))->toBeTrue();

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload(['first_name' => 'Ana', 'father_id' => $mother->id]))
        ->assertSessionHasErrors('father_id');

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload(['first_name' => 'Bea', 'birthdate' => '1950-01-01', 'mother_id' => $mother->id]))
        ->assertSessionHasErrors('mother_id');
});

it('links spouses in both directions and rejects an already married spouse', function () {
    $spouse = Resident::factory()->create(['gender' => 'Male']);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload(['spouse_id' => $spouse->id]))
        ->assertSessionHasNoErrors();

    $resident = Resident::query()->where('first_name', 'Maria')->firstOrFail();
    expect($resident->spouse_id)->toBe($spouse->id)->and($spouse->fresh()->spouse_id)->toBe($resident->id);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload(['first_name' => 'Ana', 'spouse_id' => $spouse->id]))
        ->assertSessionHasErrors('spouse_id');
});

it('adopts already registered children and moves them into the new household', function () {
    $oldHousehold = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $child = Resident::factory()->for($oldHousehold)->create([
        'barangay_id' => $this->barangay->id, 'birthdate' => '2010-01-01', 'household_role' => 'Head',
    ]);
    $oldHousehold->update(['head_resident_id' => $child->id]);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload([
        'is_head' => true, 'household_role' => null, 'dependent_ids' => [$child->id],
    ]))->assertSessionHasNoErrors();

    $mother = Resident::query()->where('first_name', 'Maria')->firstOrFail();
    $child->refresh();

    expect($child->mother_id)->toBe($mother->id)
        ->and($child->household_id)->toBe($mother->household_id)
        ->and($child->household_role)->toBe('Child')
        ->and(Household::query()->whereKey($oldHousehold->id)->exists())->toBeFalse();
});

it('keeps the previous household when other members remain and clears its head', function () {
    $oldHousehold = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $child = Resident::factory()->for($oldHousehold)->create(['barangay_id' => $this->barangay->id, 'birthdate' => '2010-01-01']);
    Resident::factory()->for($oldHousehold)->create(['barangay_id' => $this->barangay->id]);
    $oldHousehold->update(['head_resident_id' => $child->id]);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload(['dependent_ids' => [$child->id]]))
        ->assertSessionHasNoErrors();

    expect($oldHousehold->fresh()->head_resident_id)->toBeNull()
        ->and($oldHousehold->residents()->count())->toBe(1);
});

it('rejects dependents that already have that parent, are older, or are in another barangay', function () {
    $hasMother = Resident::factory()->create(['birthdate' => '2010-01-01', 'barangay_id' => $this->barangay->id, 'mother_id' => Resident::factory()->create(['gender' => 'Female'])->id]);
    $older = Resident::factory()->create(['birthdate' => '1980-01-01', 'barangay_id' => $this->barangay->id]);
    $elsewhere = Resident::factory()->create(['birthdate' => '2010-01-01']);

    foreach ([$hasMother, $older, $elsewhere] as $dependent) {
        $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload(['dependent_ids' => [$dependent->id]]))
            ->assertSessionHasErrors();
    }

    expect(Resident::query()->where('first_name', 'Maria')->exists())->toBeFalse();
});

it('rejects registering the same person twice', function () {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload());

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationPayload())
        ->assertSessionHasErrors('first_name');

    expect(Resident::query()->count())->toBe(1);
});

it('requires the core fields', function () {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), [])
        ->assertSessionHasErrors(['first_name', 'last_name', 'birthdate', 'gender', 'civil_status', 'household_mode']);
});

it('only lets encoders and super admins open the registration form', function () {
    $this->actingAs($this->encoder)->get(route('encoder.register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('encoder/register-resident')->where('lockedBarangayId', $this->barangay->id)->has('barangays', 1));

    $this->actingAs(User::factory()->withRole(RoleName::Admin)->create())->get(route('encoder.register'))->assertForbidden();
    $this->actingAs(User::factory()->withRole(RoleName::Admin)->create())->post(route('encoder.register.store'), registrationPayload())->assertForbidden();
});
