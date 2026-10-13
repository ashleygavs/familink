<?php

use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
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
function familyPayload(array $overrides = []): array
{
    return [
        'path' => 'new',
        'first_name' => 'juan',
        'last_name' => 'dela cruz',
        'birthdate' => '1980-03-14',
        'gender' => 'Male',
        'civil_status' => 'Married',
        'household_mode' => 'new',
        'street_address' => '12 Rizal Street',
        'is_head' => true,
        'consent' => true,
        'relatives' => [
            'father' => ['first_name' => 'pedro', 'last_name' => 'dela cruz', 'birthdate' => '1955-01-01', 'lives_with_me' => false],
            'mother' => ['first_name' => 'rosa', 'middle_name' => 'santos', 'last_name' => 'dela cruz', 'approximate_age' => 65, 'lives_with_me' => true],
            'spouse' => ['first_name' => 'maria', 'last_name' => 'dela cruz', 'gender' => 'Female', 'birthdate' => '1983-07-02', 'lives_with_me' => true],
            'children' => [
                ['first_name' => 'pepe', 'last_name' => 'dela cruz', 'gender' => 'Male', 'birthdate' => '2008-11-20', 'lives_with_me' => true, 'other_parent_is_spouse' => true],
            ],
            'parents_partners' => true,
        ],
        ...$overrides,
    ];
}

it('lets the first registrant list their family as listed relatives', function () {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), familyPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('encoder.register'));

    $juan = Resident::query()->where('first_name', 'Juan')->firstOrFail();
    $byName = fn (string $name) => Resident::query()->where('first_name', $name)->firstOrFail();
    [$pedro, $rosa, $maria, $pepe] = array_map($byName, ['Pedro', 'Rosa', 'Maria', 'Pepe']);

    expect(Resident::query()->count())->toBe(5)
        ->and($juan->status)->toBe(ResidentStatus::Pending)
        ->and($juan->household->head_resident_id)->toBe($juan->id)
        ->and($juan->father_id)->toBe($pedro->id)
        ->and($juan->mother_id)->toBe($rosa->id)
        ->and($juan->spouse_id)->toBe($maria->id)
        ->and($maria->spouse_id)->toBe($juan->id)
        ->and($pedro->spouse_id)->toBe($rosa->id)
        ->and($rosa->spouse_id)->toBe($pedro->id)
        ->and($pepe->father_id)->toBe($juan->id)
        ->and($pepe->mother_id)->toBe($maria->id)
        ->and(collect([$pedro, $rosa, $maria, $pepe])->every(fn (Resident $r) => $r->status === ResidentStatus::Listed && $r->listed_by_resident_id === $juan->id))->toBeTrue();
});

it('places relatives who live with the registrant in the household and others nowhere', function () {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), familyPayload());

    $juan = Resident::query()->where('first_name', 'Juan')->firstOrFail();
    $pedro = Resident::query()->where('first_name', 'Pedro')->firstOrFail();
    $rosa = Resident::query()->where('first_name', 'Rosa')->firstOrFail();
    $maria = Resident::query()->where('first_name', 'Maria')->firstOrFail();

    expect($pedro->household_id)->toBeNull()->and($pedro->barangay_id)->toBeNull()->and($pedro->household_role)->toBeNull()
        ->and($rosa->household_id)->toBe($juan->household_id)->and($rosa->household_role)->toBe('Parent')
        ->and($maria->household_role)->toBe('Spouse')->and($maria->barangay_id)->toBe($this->barangay->id);
});

it('stores an approximate age as an estimated birthdate and keeps the identifiers sequential', function () {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), familyPayload());

    $rosa = Resident::query()->where('first_name', 'Rosa')->firstOrFail();

    expect($rosa->birthdate_estimated)->toBeTrue()
        ->and($rosa->birthdate->year)->toBe(now()->year - 65)
        ->and(Resident::query()->orderBy('id')->pluck('system_id_no')->unique()->count())->toBe(5);
});

it('does not count listed relatives as pending or approved and keeps them out of approvals', function () {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), familyPayload());

    $admin = User::factory()->withRole(RoleName::Admin)->create();
    $listed = Resident::query()->where('first_name', 'Maria')->firstOrFail();

    $this->actingAs($admin)->get(route('admin.approvals'))
        ->assertInertia(fn ($page) => $page->has('pending.data', 1)->where('pending.data.0.full_name', 'Juan Dela Cruz'));

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('stats.approvedResidents', 0)->where('stats.pendingResidents', 1));

    $this->actingAs($admin)->post(route('admin.approvals.approve', $listed))->assertStatus(409);
});

it('stores deceased relatives without a household', function () {
    $payload = familyPayload();
    $payload['relatives']['mother']['is_deceased'] = true;

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), $payload)->assertSessionHasNoErrors();

    $rosa = Resident::query()->where('first_name', 'Rosa')->firstOrFail();

    expect($rosa->is_deceased)->toBeTrue()->and($rosa->household_id)->toBeNull();
});

it('requires consent, a birthdate or age, and plausible ages for relatives', function () {
    $noConsent = familyPayload(['consent' => false]);
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), $noConsent)->assertSessionHasErrors('consent');

    $noAge = familyPayload();
    unset($noAge['relatives']['mother']['approximate_age']);
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), $noAge)->assertSessionHasErrors('relatives.mother.birthdate');

    $youngParent = familyPayload();
    $youngParent['relatives']['father']['birthdate'] = '1995-01-01';
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), $youngParent)->assertSessionHasErrors('relatives.father.birthdate');

    $oldChild = familyPayload();
    $oldChild['relatives']['children'][0]['birthdate'] = '1970-01-01';
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), $oldChild)->assertSessionHasErrors('relatives.children.0.birthdate');

    expect(Resident::query()->count())->toBe(0);
});

it('rejects relatives who are already on record or listed twice', function () {
    $existing = Resident::factory()->create(['first_name' => 'Pedro', 'last_name' => 'Dela Cruz', 'birthdate' => '1955-01-01']);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), familyPayload())
        ->assertSessionHasErrors('relatives.father.first_name');

    expect(session('errors')->first('relatives.father.first_name'))->toContain($existing->system_id_no);

    $existing->delete();

    $twice = familyPayload();
    $twice['relatives']['children'][] = $twice['relatives']['children'][0];

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), $twice)->assertSessionHasErrors('relatives.children.1.first_name');
});

it('ignores family links sent with the new family path and relatives sent with the existing path', function () {
    $stranger = Resident::factory()->create(['gender' => 'Male', 'birthdate' => '1950-01-01']);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), familyPayload(['father_id' => $stranger->id, 'relatives' => null]))
        ->assertSessionHasNoErrors();

    expect(Resident::query()->where('first_name', 'Juan')->firstOrFail()->father_id)->toBeNull();

    $existingPath = familyPayload(['path' => 'existing', 'first_name' => 'luis']);
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), $existingPath)->assertSessionHasNoErrors();

    expect(Resident::query()->where('status', ResidentStatus::Listed->value)->count())->toBe(0);
});

it('lets a listed relative claim their record when they register', function () {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), familyPayload());

    $juan = Resident::query()->where('first_name', 'Juan')->firstOrFail();
    $maria = Resident::query()->where('first_name', 'Maria')->firstOrFail();
    $count = Resident::query()->count();

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), [
        'path' => 'existing',
        'claim_resident_id' => $maria->id,
        'first_name' => 'maria',
        'middle_name' => 'santos',
        'last_name' => 'dela cruz',
        'birthdate' => '1983-07-02',
        'gender' => 'Female',
        'civil_status' => 'Married',
        'household_mode' => 'existing',
        'household_id' => $juan->household_id,
        'household_role' => 'Spouse',
    ])->assertSessionHasNoErrors()->assertRedirect(route('encoder.register'));

    $claimed = $maria->fresh();

    expect(Resident::query()->count())->toBe($count)
        ->and($claimed->status)->toBe(ResidentStatus::Pending)
        ->and($claimed->system_id_no)->toBe($maria->system_id_no)
        ->and($claimed->qr_code_hash)->toBe($maria->qr_code_hash)
        ->and($claimed->middle_name)->toBe('Santos')
        ->and($claimed->spouse_id)->toBe($juan->id)
        ->and($claimed->household_id)->toBe($juan->household_id);
});

it('rejects a claim that does not match the details or an unclaimed duplicate of a listed person', function () {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), familyPayload());

    $juan = Resident::query()->where('first_name', 'Juan')->firstOrFail();
    $maria = Resident::query()->where('first_name', 'Maria')->firstOrFail();

    $payload = [
        'path' => 'existing',
        'first_name' => 'maria',
        'last_name' => 'dela cruz',
        'birthdate' => '1983-07-02',
        'gender' => 'Female',
        'civil_status' => 'Married',
        'household_mode' => 'existing',
        'household_id' => $juan->household_id,
        'household_role' => 'Spouse',
    ];

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), $payload)->assertSessionHasErrors('first_name');

    expect(session('errors')->first('first_name'))->toContain('already listed');

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), [...$payload, 'claim_resident_id' => $maria->id, 'first_name' => 'rosa'])
        ->assertSessionHasErrors('claim_resident_id');

    expect($maria->fresh()->status)->toBe(ResidentStatus::Listed);
});

it('does not let a claim bring in new relatives', function () {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), familyPayload());

    $juan = Resident::query()->where('first_name', 'Juan')->firstOrFail();
    $maria = Resident::query()->where('first_name', 'Maria')->firstOrFail();
    $count = Resident::query()->count();

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), familyPayload([
        'claim_resident_id' => $maria->id,
        'first_name' => 'maria',
        'birthdate' => '1983-07-02',
        'gender' => 'Female',
        'household_mode' => 'existing',
        'household_id' => $juan->household_id,
        'is_head' => false,
        'household_role' => 'Spouse',
    ]))->assertSessionHasNoErrors();

    expect(Resident::query()->count())->toBe($count);
});
