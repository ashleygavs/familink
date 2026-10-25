<?php

use App\Actions\Residents\LinkRelatives;
use App\Actions\Residents\MarriageRules;
use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Municipality;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $municipality = Municipality::factory()->create();
    $this->mine = Barangay::factory()->create(['municipality_id' => $municipality->id, 'name' => 'San Jose', 'code' => 'SJ']);
    $this->other = Barangay::factory()->create(['municipality_id' => $municipality->id, 'name' => 'Cabalantian', 'code' => 'CAB']);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $municipality->id]);
    $this->admin->managedBarangays()->sync([$this->mine->id]);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id]);

    $this->person = function (Barangay $barangay, array $extra = []) {
        $household = Household::factory()->create(['barangay_id' => $barangay->id]);

        return Resident::factory()->create([
            'household_id' => $household->id, 'barangay_id' => $barangay->id, 'birthdate' => '1985-01-01',
            'civil_status' => 'Single', 'middle_name' => null, 'suffix' => null, ...$extra,
        ]);
    };
    $this->link = fn (Resident $resident, array $links) => $this->actingAs($this->admin)->post(route('admin.approvals.links', $resident), $links);
});

it('refuses to link a husband or wife who lives in a barangay the admin does not handle, and leaves their record alone', function () {
    $pending = ($this->person)($this->mine, ['status' => ResidentStatus::Pending, 'gender' => 'Female']);
    $faraway = ($this->person)($this->other, ['gender' => 'Male', 'civil_status' => 'Widowed']);

    ($this->link)($pending, ['spouse_id' => $faraway->id])
        ->assertSessionHasErrors(['spouse_id' => 'You can only link a husband or wife who lives in a barangay you handle.']);

    expect($pending->fresh()->spouse_id)->toBeNull()
        ->and($faraway->fresh()->spouse_id)->toBeNull()
        ->and($faraway->fresh()->civil_status)->toBe('Widowed');
});

it('refuses the same thing when an encoder registers someone', function () {
    $faraway = ($this->person)($this->other, ['gender' => 'Male']);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), [
        'path' => 'existing', 'first_name' => 'New', 'last_name' => 'Person', 'birthdate' => '1990-01-01', 'gender' => 'Female',
        'civil_status' => 'Single', 'household_mode' => 'new', 'street_address' => '1 Test Lane', 'is_head' => true,
        'spouse_id' => $faraway->id,
    ])->assertSessionHasErrors(['spouse_id' => 'You can only link a husband or wife who lives in a barangay you handle.']);

    expect(Resident::query()->where('last_name', 'Person')->exists())->toBeFalse()
        ->and($faraway->fresh()->spouse_id)->toBeNull();
});

it('refuses a spouse who is archived, deceased or already married to someone living', function () {
    $pending = ($this->person)($this->mine, ['status' => ResidentStatus::Pending]);
    $archived = ($this->person)($this->mine, ['status' => ResidentStatus::Archived]);
    $deceased = ($this->person)($this->mine, ['is_deceased' => true]);
    $husband = ($this->person)($this->mine, ['gender' => 'Male']);
    $married = ($this->person)($this->mine, ['gender' => 'Male', 'spouse_id' => ($this->person)($this->mine, ['gender' => 'Female'])->id]);

    ($this->link)($pending, ['spouse_id' => $archived->id])->assertSessionHasErrors('spouse_id');
    ($this->link)($pending, ['spouse_id' => $deceased->id])
        ->assertSessionHasErrors(['spouse_id' => $deceased->full_name.' cannot be linked as a husband or wife because they are archived or recorded as deceased.']);
    ($this->link)($pending, ['spouse_id' => $married->id])
        ->assertSessionHasErrors(['spouse_id' => $married->full_name.' already has a spouse on record.']);

    expect($pending->fresh()->spouse_id)->toBeNull();
});

it('makes both people married when a spouse is linked, and links them in both directions', function () {
    $pending = ($this->person)($this->mine, ['status' => ResidentStatus::Pending, 'gender' => 'Female', 'civil_status' => 'Single']);
    $husband = ($this->person)($this->mine, ['gender' => 'Male', 'civil_status' => 'Single']);

    ($this->link)($pending, ['spouse_id' => $husband->id])->assertSessionHasNoErrors();

    expect($pending->fresh()->spouse_id)->toBe($husband->id)
        ->and($husband->fresh()->spouse_id)->toBe($pending->id)
        ->and($pending->fresh()->civil_status)->toBe('Married')
        ->and($husband->fresh()->civil_status)->toBe('Married');
});

it('lets a widowed person marry again, replacing the link to the one who died', function () {
    $late = ($this->person)($this->mine, ['gender' => 'Male', 'is_deceased' => true]);
    $widow = ($this->person)($this->mine, ['gender' => 'Female', 'civil_status' => 'Widowed', 'spouse_id' => $late->id]);
    $pending = ($this->person)($this->mine, ['status' => ResidentStatus::Pending, 'gender' => 'Male']);

    ($this->link)($pending, ['spouse_id' => $widow->id])->assertSessionHasNoErrors();

    expect($widow->fresh()->spouse_id)->toBe($pending->id)
        ->and($widow->fresh()->civil_status)->toBe('Married')
        ->and($pending->fresh()->spouse_id)->toBe($widow->id);
});

it('lets two people who are both still waiting for approval be linked, as a couple registering together', function () {
    $wife = ($this->person)($this->mine, ['status' => ResidentStatus::Pending, 'gender' => 'Female']);
    $husband = ($this->person)($this->mine, ['status' => ResidentStatus::Pending, 'gender' => 'Male']);

    ($this->link)($wife, ['spouse_id' => $husband->id])->assertSessionHasNoErrors();

    expect($wife->fresh()->spouse_id)->toBe($husband->id)->and($husband->fresh()->spouse_id)->toBe($wife->id);
});

it('writes nothing at all when the person was married by someone else a moment before', function () {
    $pending = ($this->person)($this->mine, ['status' => ResidentStatus::Pending, 'gender' => 'Female']);
    $husband = ($this->person)($this->mine, ['gender' => 'Male']);
    $other = ($this->person)($this->mine, ['gender' => 'Female']);
    $husband->update(['spouse_id' => $other->id, 'civil_status' => 'Married']);

    // The form was checked while he was still single; the action runs once he is not.
    expect(fn () => app(LinkRelatives::class)->handle($pending, ['spouse_id' => $husband->id]))->toThrow(ValidationException::class);

    expect($pending->fresh()->spouse_id)->toBeNull()
        ->and($pending->fresh()->civil_status)->toBe('Single')
        ->and($husband->fresh()->spouse_id)->toBe($other->id);
});

it('keeps the shared rules in one place', function () {
    $rules = app(MarriageRules::class);
    $mother = ($this->person)($this->mine, ['gender' => 'Female']);
    $child = ($this->person)($this->mine, ['mother_id' => $mother->id, 'birthdate' => '2005-01-01']);
    $sibling = ($this->person)($this->mine, ['mother_id' => $mother->id, 'birthdate' => '2007-01-01']);
    $stranger = ($this->person)($this->mine);

    expect($rules->areCloseRelatives($mother, $child))->toBeTrue()
        ->and($rules->areCloseRelatives($child, $sibling))->toBeTrue()
        ->and($rules->areCloseRelatives($child, $stranger))->toBeFalse()
        ->and($rules->hasLivingSpouse($stranger))->toBeFalse();

    $late = ($this->person)($this->mine, ['is_deceased' => true]);
    $widow = ($this->person)($this->mine, ['spouse_id' => $late->id]);
    $wife = ($this->person)($this->mine, ['spouse_id' => $stranger->id]);

    expect($rules->hasLivingSpouse($widow))->toBeFalse()->and($rules->hasLivingSpouse($wife))->toBeTrue()
        ->and($rules->linkProblem($stranger))->toBeNull()
        ->and($rules->linkProblem($wife))->toBe($wife->full_name.' already has a spouse on record.');
});

it('still lets an admin record a marriage exactly as before', function () {
    $husband = ($this->person)($this->mine, ['gender' => 'Male']);
    $wife = ($this->person)($this->mine, ['gender' => 'Female']);

    $this->actingAs($this->admin)->post(route('admin.residents.marriage', $husband), ['spouse_id' => $wife->id])->assertSessionHasNoErrors();

    expect($husband->fresh()->spouse_id)->toBe($wife->id)->and($wife->fresh()->civil_status)->toBe('Married');

    $this->actingAs($this->admin)->post(route('admin.residents.marriage', $husband), ['spouse_id' => ($this->person)($this->mine)->id])
        ->assertSessionHasErrors('spouse_id');
});
