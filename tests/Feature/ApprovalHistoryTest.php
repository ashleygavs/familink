<?php

use App\Actions\Residents\LinkRelatives;
use App\Actions\Residents\RecordResidentChange;
use App\Actions\Residents\UnlinkRelative;
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
    $this->mine = Barangay::factory()->create(['municipality_id' => $municipality->id, 'name' => 'San Jose', 'code' => 'SJ']);
    $this->other = Barangay::factory()->create(['municipality_id' => $municipality->id, 'name' => 'Cabalantian', 'code' => 'CAB']);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $municipality->id, 'name' => 'Local Admin']);
    $this->admin->managedBarangays()->sync([$this->mine->id]);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id, 'name' => 'The Encoder']);

    $this->person = function (Barangay $barangay, array $extra = []) {
        $household = $extra['household'] ?? Household::factory()->create(['barangay_id' => $barangay->id]);
        unset($extra['household']);

        return Resident::factory()->create([
            'household_id' => $household->id, 'barangay_id' => $barangay->id, 'birthdate' => '1985-01-01',
            'civil_status' => 'Single', 'middle_name' => null, 'suffix' => null, ...$extra,
        ]);
    };
    $this->pending = fn (array $extra = []) => ($this->person)($this->mine, ['status' => ResidentStatus::Pending, ...$extra]);
    $this->history = fn (Resident $resident, ?string $type = null) => ResidentChange::query()
        ->where('resident_id', $resident->id)->when($type, fn ($query) => $query->where('type', $type))->orderBy('id')->get();
    $this->link = fn (Resident $resident, array $links) => $this->actingAs($this->admin)->post(route('admin.approvals.links', $resident), $links);
});

it('records which encoder registered a person, and where', function () {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), [
        'path' => 'existing', 'first_name' => 'New', 'last_name' => 'Person', 'birthdate' => '1990-01-01', 'gender' => 'Female',
        'civil_status' => 'Single', 'household_mode' => 'new', 'street_address' => '1 Test Lane', 'is_head' => true,
    ])->assertSessionHasNoErrors();

    $resident = Resident::query()->where('last_name', 'Person')->firstOrFail();
    $line = ($this->history)($resident, ResidentChange::REGISTERED)->sole();

    expect($line->summary)->toBe('Registered by The Encoder in San Jose.')
        ->and($line->changed_by_user_id)->toBe($this->encoder->id)
        ->and($line->data['barangay_id'])->toBe($this->mine->id);
});

it('records the family links made while registering, on both people, by the encoder', function () {
    $husband = ($this->person)($this->mine, ['gender' => 'Male', 'first_name' => 'Ben', 'last_name' => 'Cruz']);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), [
        'path' => 'existing', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'birthdate' => '1990-01-01', 'gender' => 'Female',
        'civil_status' => 'Single', 'household_mode' => 'new', 'street_address' => '1 Test Lane', 'is_head' => true,
        'spouse_id' => $husband->id,
    ])->assertSessionHasNoErrors();

    $wife = Resident::query()->where('first_name', 'Ana')->firstOrFail();

    expect(($this->history)($wife, ResidentChange::FAMILY_LINKED)->sole()->summary)->toBe('Linked Ben Cruz as spouse.')
        ->and(($this->history)($husband, ResidentChange::FAMILY_LINKED)->sole()->summary)->toBe('Linked Ana Cruz as spouse.')
        ->and(($this->history)($wife, ResidentChange::FAMILY_LINKED)->sole()->changed_by_user_id)->toBe($this->encoder->id);
});

it('records who approved a registration', function () {
    $resident = ($this->pending)();

    $this->actingAs($this->admin)->post(route('admin.approvals.approve', $resident))->assertRedirect();

    $line = ($this->history)($resident, ResidentChange::APPROVED)->sole();

    expect($line->summary)->toBe('Approved by Local Admin.')->and($line->changed_by_user_id)->toBe($this->admin->id)
        ->and($resident->fresh()->approved_by_user_id)->toBe($this->admin->id);
});

it('needs a reason to archive a registration, and says so in plain words', function () {
    $resident = ($this->pending)();
    $archive = fn (array $body) => $this->actingAs($this->admin)->post(route('admin.approvals.archive', $resident), $body);

    $archive([])->assertSessionHasErrors(['reason' => 'Please write why this registration is being archived.']);
    $archive(['reason' => '   '])->assertSessionHasErrors(['reason' => 'Please write why this registration is being archived.']);
    $archive(['reason' => 'no'])->assertSessionHasErrors(['reason' => 'Please write a little more: at least 5 characters.']);
    $archive(['reason' => str_repeat('x', 301)])->assertSessionHasErrors(['reason' => 'Please keep the reason to 300 characters or fewer.']);

    expect($resident->fresh()->status)->toBe(ResidentStatus::Pending)
        ->and(($this->history)($resident, ResidentChange::ARCHIVED))->toHaveCount(0);
});

it('records who archived a registration, when and why', function () {
    $resident = ($this->pending)();

    $this->actingAs($this->admin)->post(route('admin.approvals.archive', $resident), ['reason' => '  Duplicate of FL-2026-SJ-00001  '])->assertRedirect();

    $line = ($this->history)($resident, ResidentChange::ARCHIVED)->sole();

    expect($resident->fresh()->status)->toBe(ResidentStatus::Archived)->and($resident->fresh()->approved_by_user_id)->toBeNull()
        ->and($line->summary)->toBe('Registration archived by Local Admin: Duplicate of FL-2026-SJ-00001')
        ->and($line->changed_by_user_id)->toBe($this->admin->id)
        ->and($line->data['reason'])->toBe('Duplicate of FL-2026-SJ-00001');
});

it('keeps the whole reason even when the visible line has to be shortened', function () {
    $resident = ($this->pending)();
    $reason = str_repeat('word ', 60);

    $this->actingAs($this->admin)->post(route('admin.approvals.archive', $resident), ['reason' => $reason])->assertRedirect();

    $line = ($this->history)($resident, ResidentChange::ARCHIVED)->sole();

    expect(mb_strlen($line->summary))->toBeLessThanOrEqual(240)->and($line->data['reason'])->toBe(trim($reason));
});

it('does not let an admin archive a registration from a barangay they do not handle', function () {
    $outside = ($this->person)($this->other, ['status' => ResidentStatus::Pending]);

    $this->actingAs($this->admin)->post(route('admin.approvals.archive', $outside), ['reason' => 'Not my area at all'])->assertForbidden();

    expect($outside->fresh()->status)->toBe(ResidentStatus::Pending)->and(($this->history)($outside))->toHaveCount(0);
});

it('releases the spouse of an archived registration and restores their earlier civil status', function () {
    $pending = ($this->pending)(['gender' => 'Female', 'civil_status' => 'Single', 'first_name' => 'Pat', 'last_name' => 'Dee']);
    $partner = ($this->person)($this->mine, ['gender' => 'Male', 'civil_status' => 'Widowed', 'first_name' => 'Sam', 'last_name' => 'Lee']);

    ($this->link)($pending, ['spouse_id' => $partner->id])->assertSessionHasNoErrors();

    expect($partner->fresh()->civil_status)->toBe('Married');

    $this->actingAs($this->admin)->post(route('admin.approvals.archive', $pending), ['reason' => 'Entered by mistake'])->assertRedirect();

    expect($partner->fresh()->spouse_id)->toBeNull()
        ->and($partner->fresh()->civil_status)->toBe('Widowed')
        ->and($pending->fresh()->spouse_id)->toBeNull()
        ->and(($this->history)($partner, ResidentChange::FAMILY_UNLINKED)->sole()->summary)->toBe('Removed Pat Dee as spouse. Civil status is back to Widowed.')
        ->and(($this->history)($pending, ResidentChange::FAMILY_UNLINKED)->sole()->summary)->toBe('Removed Sam Lee as spouse. Civil status is back to Single.');
});

it('releases the children linked to an archived registration', function () {
    $household = Household::factory()->create(['barangay_id' => $this->mine->id]);
    $pending = ($this->pending)(['household' => $household, 'gender' => 'Female', 'birthdate' => '1980-01-01', 'first_name' => 'Mum', 'last_name' => 'Dee']);
    $child = ($this->person)($this->mine, ['birthdate' => '2005-01-01', 'first_name' => 'Kid', 'last_name' => 'Dee']);

    ($this->link)($pending, ['dependent_ids' => [$child->id]])->assertSessionHasNoErrors();

    expect($child->fresh()->mother_id)->toBe($pending->id);

    $this->actingAs($this->admin)->post(route('admin.approvals.archive', $pending), ['reason' => 'Entered by mistake'])->assertRedirect();

    expect($child->fresh()->mother_id)->toBeNull()
        ->and(($this->history)($child, ResidentChange::FAMILY_UNLINKED)->sole()->summary)->toBe('Removed Mum Dee as mother.')
        ->and(($this->history)($pending, ResidentChange::FAMILY_UNLINKED)->sole()->summary)->toBe('Removed Kid Dee as child.');
});

it('records the links an admin confirms at approval, on every record that changed', function () {
    $household = Household::factory()->create(['barangay_id' => $this->mine->id]);
    $pending = ($this->pending)(['household' => $household, 'gender' => 'Female', 'birthdate' => '1980-01-01', 'first_name' => 'Mum', 'last_name' => 'Dee']);
    $father = ($this->person)($this->mine, ['gender' => 'Male', 'birthdate' => '1950-01-01', 'first_name' => 'Gramps', 'last_name' => 'Dee']);
    $child = ($this->person)($this->mine, ['birthdate' => '2005-01-01', 'first_name' => 'Kid', 'last_name' => 'Dee']);

    ($this->link)($pending, ['father_id' => $father->id, 'dependent_ids' => [$child->id]])->assertSessionHasNoErrors();

    $mum = ($this->history)($pending, ResidentChange::FAMILY_LINKED)->pluck('summary')->all();
    $kid = ($this->history)($child, ResidentChange::FAMILY_LINKED)->sole();

    expect($mum)->toBe(['Linked Gramps Dee as father.', 'Linked Kid Dee as child.'])
        ->and($kid->summary)->toBe("Linked Mum Dee as mother and moved into household {$household->household_code}.")
        ->and($kid->changed_by_user_id)->toBe($this->admin->id)
        ->and(($this->history)($father))->toHaveCount(0);
});

it('records the links an admin removes at approval, on both people', function () {
    $pending = ($this->pending)(['gender' => 'Female', 'first_name' => 'Pat', 'last_name' => 'Dee']);
    $partner = ($this->person)($this->mine, ['gender' => 'Male', 'first_name' => 'Sam', 'last_name' => 'Lee']);
    ($this->link)($pending, ['spouse_id' => $partner->id])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post(route('admin.approvals.unlink', $pending), ['kind' => 'spouse'])->assertRedirect();

    expect($partner->fresh()->spouse_id)->toBeNull()->and($partner->fresh()->civil_status)->toBe('Single')
        ->and(($this->history)($pending, ResidentChange::FAMILY_UNLINKED)->sole()->changed_by_user_id)->toBe($this->admin->id)
        ->and(($this->history)($partner, ResidentChange::FAMILY_UNLINKED))->toHaveCount(1);
});

it('writes no history when there is nobody to credit, so older callers keep working', function () {
    $pending = ($this->pending)(['gender' => 'Female']);
    $partner = ($this->person)($this->mine, ['gender' => 'Male']);

    app(LinkRelatives::class)->handle($pending, ['spouse_id' => $partner->id]);
    app(UnlinkRelative::class)->handle($pending, 'spouse');

    expect(ResidentChange::query()->count())->toBe(0)->and($partner->fresh()->spouse_id)->toBeNull();
});

it('shows the person approving who registered each waiting resident', function () {
    $registered = ($this->pending)(['first_name' => 'Registered']);
    $unknown = ($this->pending)(['first_name' => 'Unknown']);
    app(RecordResidentChange::class)->handle($registered, $this->encoder, ResidentChange::REGISTERED, 'Registered by The Encoder in San Jose.');

    $this->actingAs($this->admin)->get(route('admin.approvals'))->assertInertia(fn ($page) => $page
        ->where('pending.data', fn ($rows) => collect($rows)->firstWhere('id', $registered->id)['registered_by'] === 'The Encoder'
            && collect($rows)->firstWhere('id', $unknown->id)['registered_by'] === null));
});

it('shows the new history lines on the resident page, to the admin who handles that barangay only', function () {
    $resident = ($this->pending)();
    $this->actingAs($this->admin)->post(route('admin.approvals.approve', $resident))->assertRedirect();

    $this->actingAs($this->admin)->get(route('admin.residents.show', $resident))
        ->assertInertia(fn ($page) => $page->where('history.0.summary', 'Approved by Local Admin.'));

    $outsideAdmin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $this->mine->municipality_id]);
    $outsideAdmin->managedBarangays()->sync([$this->other->id]);

    $this->actingAs($outsideAdmin)->get(route('admin.residents.show', $resident))->assertForbidden();
});

it('keeps a history line short enough to read while keeping the details', function () {
    $resident = ($this->pending)();

    $line = app(RecordResidentChange::class)->handle($resident, $this->admin, ResidentChange::CORRECTION, str_repeat('long ', 100), ['full' => 'kept']);

    expect(mb_strlen($line->summary))->toBeLessThanOrEqual(240)->and($line->data)->toBe(['full' => 'kept']);
});
