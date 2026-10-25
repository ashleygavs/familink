<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['code' => 'SJ']);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create();
    $this->admin->managedBarangays()->sync([$this->barangay->id]);

    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->make = fn (array $extra = []) => Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'middle_name' => null, 'birthdate' => '1995-05-05', ...$extra,
    ]);
});

it('records a death, widows the spouse and hands the household to a new head', function () {
    $husband = ($this->make)(['gender' => 'Male']);
    $wife = ($this->make)(['gender' => 'Female', 'spouse_id' => $husband->id, 'civil_status' => 'Married']);
    $husband->update(['spouse_id' => $wife->id, 'civil_status' => 'Married']);
    $this->household->passHeadTo($husband);

    $this->actingAs($this->admin)->post(route('admin.residents.deceased', $husband), ['deceased_at' => '2026-01-02', 'new_head_id' => $wife->id])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    expect($husband->fresh()->is_deceased)->toBeTrue()
        ->and($husband->fresh()->deceased_at->toDateString())->toBe('2026-01-02')
        ->and($wife->fresh()->civil_status)->toBe('Widowed')
        ->and($this->household->fresh()->head_resident_id)->toBe($wife->id)
        ->and($wife->fresh()->household_role)->toBe('Head')
        ->and($husband->fresh()->household_role)->not->toBe('Head')
        ->and($husband->changes()->where('type', ResidentChange::DECEASED)->exists())->toBeTrue()
        ->and($wife->changes()->where('type', ResidentChange::WIDOWED)->exists())->toBeTrue();
});

it('requires a new head when the deceased heads a household that still has living members', function () {
    $head = ($this->make)();
    $other = ($this->make)();
    $this->household->passHeadTo($head);

    $this->actingAs($this->admin)->post(route('admin.residents.deceased', $head), [])
        ->assertSessionHasErrors('new_head_id');

    expect($head->fresh()->is_deceased)->toBeFalse();

    $this->actingAs($this->admin)->post(route('admin.residents.deceased', $head), ['new_head_id' => $other->id])
        ->assertSessionHasNoErrors();

    expect($this->household->fresh()->head_resident_id)->toBe($other->id);
});

it('undoes a death recorded by mistake and restores the spouse', function () {
    $husband = ($this->make)();
    $wife = ($this->make)(['spouse_id' => $husband->id, 'civil_status' => 'Married']);
    $husband->update(['spouse_id' => $wife->id, 'civil_status' => 'Married']);

    $this->actingAs($this->admin)->post(route('admin.residents.deceased', $husband), [])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('admin.residents.living', $husband))->assertSessionHasNoErrors();

    expect($husband->fresh()->is_deceased)->toBeFalse()
        ->and($husband->fresh()->deceased_at)->toBeNull()
        ->and($wife->fresh()->civil_status)->toBe('Married');
});

it('lets a widow remarry and keeps deceased residents out of household moves', function () {
    $husband = ($this->make)(['gender' => 'Male']);
    $wife = ($this->make)(['gender' => 'Female', 'spouse_id' => $husband->id, 'civil_status' => 'Married']);
    $husband->update(['spouse_id' => $wife->id, 'civil_status' => 'Married']);
    $this->actingAs($this->admin)->post(route('admin.residents.deceased', $husband), ['new_head_id' => $wife->id]);

    $suitor = ($this->make)(['gender' => 'Male']);

    $this->actingAs($this->admin)->post(route('admin.residents.marriage', $wife), ['spouse_id' => $suitor->id])
        ->assertSessionHasNoErrors();

    expect($wife->fresh()->spouse_id)->toBe($suitor->id);

    $this->actingAs($this->admin)->post(route('admin.residents.household', $husband), ['street_address' => 'Elsewhere', 'household_role' => 'Other'])
        ->assertSessionHasErrors('household_id');
});

it('corrects a registration and keeps what changed in the history', function () {
    $resident = ($this->make)(['first_name' => 'Jonh', 'last_name' => 'Cruz', 'gender' => 'Male']);

    $this->actingAs($this->admin)->patch(route('admin.residents.correct', $resident), [
        'first_name' => 'john', 'middle_name' => '', 'last_name' => 'Cruz', 'suffix' => 'Jr.',
        'maiden_name' => '', 'birthdate' => '1995-05-06', 'gender' => 'Male',
    ])->assertSessionHasNoErrors();

    $resident->refresh();

    expect($resident->first_name)->toBe('John')
        ->and($resident->first_name_key)->toBe('john')
        ->and($resident->suffix)->toBe('Jr.')
        ->and($resident->birthdate->toDateString())->toBe('1995-05-06')
        ->and($resident->changes()->where('type', ResidentChange::CORRECTION)->first()->summary)
        ->toContain('First name Jonh to John')->toContain('Birthdate May 5, 1995 to May 6, 1995');
});

it('refuses corrections that contradict the family on record', function (array $change, string $field) {
    $father = ($this->make)(['birthdate' => '1970-01-01', 'gender' => 'Male']);
    $child = ($this->make)(['birthdate' => '2000-01-01', 'father_id' => $father->id, 'first_name' => 'Kid', 'last_name' => 'Cruz']);
    ($this->make)(['first_name' => 'Twin', 'last_name' => 'Cruz', 'birthdate' => '2000-02-02']);

    $payload = ['first_name' => 'Kid', 'last_name' => 'Cruz', 'birthdate' => '2000-01-01', 'gender' => $child->gender, ...$change];

    $this->actingAs($this->admin)->patch(route('admin.residents.correct', $child), $payload)->assertSessionHasErrors($field);
})->with([
    'parent too close in age' => [['birthdate' => '1980-01-01'], 'birthdate'],
    'duplicate of another resident' => [['first_name' => 'Twin', 'birthdate' => '2000-02-02'], 'first_name'],
    'nothing changed' => [[], 'first_name'],
]);

it('keeps admins to their own barangays', function () {
    $outsider = Resident::factory()->create(['barangay_id' => Barangay::factory()->create()->id]);

    $this->actingAs($this->admin)->post(route('admin.residents.deceased', $outsider), [])->assertNotFound();
    $this->actingAs($this->admin)->post(route('admin.residents.living', $outsider))->assertNotFound();
    $this->actingAs($this->admin)->patch(route('admin.residents.correct', $outsider), [])->assertNotFound();
});
