<?php

use App\Actions\Residents\RecordResidentChange;
use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Municipality;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;

beforeEach(function () {
    $municipality = Municipality::factory()->create();
    $this->barangay = Barangay::factory()->create(['municipality_id' => $municipality->id, 'name' => 'San Jose', 'code' => 'SJ']);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $municipality->id, 'name' => 'Alma Admin']);
    $this->admin->managedBarangays()->sync([$this->barangay->id]);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id, 'name' => 'Erin Encoder']);

    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->waiting = function (array $extra = []) {
        $resident = Resident::factory()->create([
            'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id, 'status' => ResidentStatus::Pending,
            'first_name' => 'Pedro', 'last_name' => 'Santos', 'middle_name' => null, 'suffix' => null,
            'birthdate' => '2010-03-04', 'gender' => 'Male', 'civil_status' => 'Single', ...$extra,
        ]);
        app(RecordResidentChange::class)->handle($resident, $this->encoder, ResidentChange::REGISTERED, 'Registered by Erin Encoder in San Jose.');

        return $resident;
    };
});

it('shows who registered a waiting resident and lets an admin approve them', function () {
    $resident = ($this->waiting)();
    $this->actingAs($this->admin);

    visit('/admin/approvals')
        ->assertSee('Pedro Santos')
        ->assertSee('Erin Encoder')
        ->press('Approve')
        ->assertSee('All caught up')
        ->assertNoJavaScriptErrors();

    expect($resident->fresh()->status)->toBe(ResidentStatus::Approved)
        ->and($resident->fresh()->approved_by_user_id)->toBe($this->admin->id)
        ->and(ResidentChange::query()->where('resident_id', $resident->id)->where('type', ResidentChange::APPROVED)->value('summary'))->toBe('Approved by Alma Admin.');
});

it('asks for a reason before archiving, then archives and records it', function () {
    $resident = ($this->waiting)();
    $this->actingAs($this->admin);

    visit('/admin/approvals')
        ->press('Archive')
        ->assertSee('Archive this registration?')
        ->click('button:has-text("Yes, archive")')
        ->assertSee('Please write why this registration is being archived.')
        ->type('#archive-reason', 'Duplicate of another record')
        ->click('button:has-text("Yes, archive")')
        ->assertSee('All caught up')
        ->assertNoJavaScriptErrors();

    expect($resident->fresh()->status)->toBe(ResidentStatus::Archived)
        ->and(ResidentChange::query()->where('resident_id', $resident->id)->where('type', ResidentChange::ARCHIVED)->value('summary'))
        ->toBe('Registration archived by Alma Admin: Duplicate of another record');
});

it('lets an admin confirm a suggested family link before approving', function () {
    $father = Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id, 'status' => ResidentStatus::Approved,
        'first_name' => 'Juan', 'last_name' => 'Santos', 'middle_name' => null, 'suffix' => null,
        'birthdate' => '1980-01-01', 'gender' => 'Male', 'civil_status' => 'Married',
    ]);
    $child = ($this->waiting)();
    $this->actingAs($this->admin);

    visit('/admin/approvals')
        ->assertSee('Juan Santos')
        ->click('button:has-text("Yes, confirm link")')
        ->assertNoJavaScriptErrors();

    expect($child->fresh()->father_id)->toBe($father->id)
        ->and(ResidentChange::query()->where('resident_id', $child->id)->where('type', ResidentChange::FAMILY_LINKED)->value('summary'))->toBe('Linked Juan Santos as father.');
});
