<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['code' => 'SJ']);
    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create();

    $this->person = fn (array $extra = []) => Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id, 'birthdate' => '1980-01-01', ...$extra,
    ]);
    $this->pending = fn (array $extra = []) => Resident::factory()->pending()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id, 'birthdate' => '2000-01-01', ...$extra,
    ]);
    $this->link = fn (Resident $resident, array $links) => $this->actingAs($this->admin)->post(route('admin.approvals.links', $resident), $links);
});

it('refuses a parent who is too close in age to the child', function () {
    $child = ($this->pending)(['birthdate' => '2000-01-01']);
    $tooClose = ($this->person)(['gender' => 'Male', 'birthdate' => '1995-01-01']);

    ($this->link)($child, ['father_id' => $tooClose->id])
        ->assertSessionHasErrors(['father_id' => 'The father must be at least 12 years older than the resident.']);

    expect($child->fresh()->father_id)->toBeNull();
});

it('accepts a parent who is old enough', function () {
    $child = ($this->pending)(['birthdate' => '2000-01-01']);
    $father = ($this->person)(['gender' => 'Male', 'birthdate' => '1988-01-01']);

    ($this->link)($child, ['father_id' => $father->id])->assertSessionHasNoErrors();

    expect($child->fresh()->father_id)->toBe($father->id);
});

it('refuses a child who is too close in age to the parent', function () {
    $parent = ($this->pending)(['birthdate' => '1990-01-01', 'gender' => 'Female']);
    $tooClose = ($this->person)(['birthdate' => '1999-01-01']);

    ($this->link)($parent, ['dependent_ids' => [$tooClose->id]])
        ->assertSessionHasErrors(['dependent_ids' => $tooClose->full_name.' must be at least 12 years younger than the resident.']);
});

it('refuses to link a person as the spouse of their own parent', function () {
    $father = ($this->person)(['gender' => 'Male', 'birthdate' => '1960-01-01']);
    $child = ($this->pending)(['birthdate' => '1990-01-01', 'father_id' => $father->id]);

    ($this->link)($child, ['spouse_id' => $father->id])
        ->assertSessionHasErrors(['spouse_id' => 'A person cannot be married to their own parent.']);
});

it('refuses to link a person as the spouse of their own child', function () {
    $parent = ($this->pending)(['birthdate' => '1960-01-01', 'gender' => 'Female']);
    $child = ($this->person)(['birthdate' => '1990-01-01', 'mother_id' => $parent->id]);

    ($this->link)($parent, ['spouse_id' => $child->id])
        ->assertSessionHasErrors(['spouse_id' => 'A person cannot be married to their own child.']);
});

it('refuses to link a person as the spouse of their own brother or sister', function () {
    $father = ($this->person)(['gender' => 'Male', 'birthdate' => '1960-01-01']);
    $sister = ($this->person)(['gender' => 'Female', 'birthdate' => '1990-01-01', 'father_id' => $father->id]);
    $brother = ($this->pending)(['gender' => 'Male', 'birthdate' => '1992-01-01']);

    ($this->link)($brother, ['father_id' => $father->id, 'spouse_id' => $sister->id])
        ->assertSessionHasErrors(['spouse_id' => 'A person cannot be married to their own brother or sister.']);
});

it('refuses a spouse who is the person\'s own parent in the same request', function () {
    $father = ($this->person)(['gender' => 'Male', 'birthdate' => '1960-01-01']);
    $child = ($this->pending)(['birthdate' => '1990-01-01']);

    ($this->link)($child, ['father_id' => $father->id, 'spouse_id' => $father->id])
        ->assertSessionHasErrors('spouse_id');
});

it('refuses a spouse under 16', function () {
    $adult = ($this->pending)(['birthdate' => '1990-01-01']);
    $young = ($this->person)(['birthdate' => now()->subYears(14)->toDateString()]);

    ($this->link)($adult, ['spouse_id' => $young->id])
        ->assertSessionHasErrors(['spouse_id' => 'Both people must be at least 16 years old to be recorded as married.']);
});

it('still links an ordinary spouse', function () {
    $adult = ($this->pending)(['birthdate' => '1990-01-01', 'gender' => 'Female']);
    $spouse = ($this->person)(['birthdate' => '1988-01-01', 'gender' => 'Male']);

    ($this->link)($adult, ['spouse_id' => $spouse->id])->assertSessionHasNoErrors();

    expect($adult->fresh()->spouse_id)->toBe($spouse->id)->and($spouse->fresh()->spouse_id)->toBe($adult->id);
});
