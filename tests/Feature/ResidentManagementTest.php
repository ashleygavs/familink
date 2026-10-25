<?php

use App\Enums\EventStatus;
use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Municipality;
use App\Models\ReliefEvent;
use App\Models\ReliefLog;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['code' => 'SJ']);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create();
    $this->admin->managedBarangays()->sync([$this->barangay->id]);

    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id, 'household_code' => 'HH-SJ-0001']);
    $this->make = fn (array $extra = []) => Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'middle_name' => null, 'birthdate' => '1995-05-05', ...$extra,
    ]);
});

it('records a marriage between two approved residents and keeps a history of it', function () {
    $groom = ($this->make)(['first_name' => 'Miguel', 'last_name' => 'Cruz', 'gender' => 'Male']);
    $bride = ($this->make)(['first_name' => 'Ana', 'last_name' => 'Reyes', 'gender' => 'Female', 'household_id' => Household::factory()->create(['barangay_id' => $this->barangay->id])->id]);

    $this->actingAs($this->admin)->post(route('admin.residents.marriage', $groom), ['spouse_id' => $bride->id])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    expect($groom->fresh()->spouse_id)->toBe($bride->id)
        ->and($bride->fresh()->spouse_id)->toBe($groom->id)
        ->and($groom->fresh()->civil_status)->toBe('Married')
        ->and($bride->fresh()->civil_status)->toBe('Married')
        ->and($bride->fresh()->last_name)->toBe('Reyes')
        ->and(ResidentChange::query()->where('type', ResidentChange::MARRIAGE)->count())->toBe(2)
        ->and($groom->changes()->first()->summary)->toBe('Married to Ana Reyes.')
        ->and($groom->changes()->first()->changed_by_user_id)->toBe($this->admin->id);
});

it('lets one spouse take the other\'s surname and keeps the old one as a maiden name', function () {
    $groom = ($this->make)(['first_name' => 'Miguel', 'last_name' => 'Cruz', 'gender' => 'Male']);
    $bride = ($this->make)(['first_name' => 'Ana', 'last_name' => 'Reyes', 'gender' => 'Female']);

    $this->actingAs($this->admin)->post(route('admin.residents.marriage', $groom), ['spouse_id' => $bride->id, 'takes_surname_id' => $bride->id])
        ->assertSessionHasNoErrors();

    $bride->refresh();

    expect($bride->last_name)->toBe('Cruz')
        ->and($bride->maiden_name)->toBe('Reyes')
        ->and($bride->last_name_key)->toBe('cruz')
        ->and($bride->full_name)->toBe('Ana Cruz')
        ->and($bride->changes()->first()->summary)->toContain('Now uses the surname Cruz (was Reyes)')
        ->and($groom->fresh()->last_name)->toBe('Cruz');

    $stranger = ($this->make)(['gender' => 'Female']);
    $this->actingAs($this->admin)->post(route('admin.residents.marriage', ($this->make)(['gender' => 'Male'])), ['spouse_id' => $stranger->id, 'takes_surname_id' => $bride->id])
        ->assertSessionHasErrors('takes_surname_id');
});

it('refuses marriages that cannot be recorded', function (Closure $setup, string $message) {
    [$first, $second] = $setup($this->make);

    $this->actingAs($this->admin)->post(route('admin.residents.marriage', $first), ['spouse_id' => $second->id])
        ->assertSessionHasErrors('spouse_id');

    expect(session('errors')->first('spouse_id'))->toContain($message)
        ->and($first->fresh()->spouse_id)->toBeNull();
})->with([
    'themselves' => [fn ($make) => (function () use ($make) {
        $person = $make();

        return [$person, $person];
    })(), 'themselves'],
    'already married' => [fn ($make) => [$make(), $make(['spouse_id' => $make()->id])], 'already has a spouse'],
    'under sixteen' => [fn ($make) => [$make(), $make(['birthdate' => now()->subYears(12)->toDateString()])], 'too young'],
    'not approved' => [fn ($make) => [$make(), $make(['status' => ResidentStatus::Pending])], 'approved, living'],
    'deceased' => [fn ($make) => [$make(), $make(['is_deceased' => true])], 'approved, living'],
    'parent and child' => [fn ($make) => (function () use ($make) {
        $parent = $make();

        return [$parent, $make(['father_id' => $parent->id])];
    })(), 'parent and child'],
    'brother and sister' => [fn ($make) => (function () use ($make) {
        $mother = $make();

        return [$make(['mother_id' => $mother->id]), $make(['mother_id' => $mother->id])];
    })(), 'brother and sister'],
]);

it('only lets an admin record changes for residents in their own barangays', function () {
    $other = Barangay::factory()->create();
    $foreign = Resident::factory()->create(['barangay_id' => $other->id, 'household_id' => Household::factory()->create(['barangay_id' => $other->id])->id, 'birthdate' => '1995-01-01']);
    $mine = ($this->make)();

    $this->actingAs($this->admin)->post(route('admin.residents.marriage', $foreign), ['spouse_id' => $mine->id])->assertNotFound();
    $this->actingAs($this->admin)->post(route('admin.residents.marriage', $mine), ['spouse_id' => $foreign->id])->assertSessionHasErrors('spouse_id');
    $this->actingAs($this->admin)->get(route('admin.residents.show', $foreign))->assertNotFound();
    $this->actingAs($this->admin)->post(route('admin.residents.household', $foreign), ['street_address' => '1 Test', 'household_role' => 'Other'])->assertNotFound();

    foreach ([RoleName::User, RoleName::Stakeholder] as $role) {
        $this->actingAs(User::factory()->withRole($role)->create(['barangay_id' => $this->barangay->id]))
            ->post(route('admin.residents.marriage', $mine), ['spouse_id' => ($this->make)()->id])->assertForbidden();
    }

    expect(ResidentChange::query()->count())->toBe(0);
});

it('suggests only people who could marry the resident', function () {
    $resident = ($this->make)(['first_name' => 'Miguel', 'last_name' => 'Cruz', 'gender' => 'Male', 'mother_id' => ($this->make)()->id]);
    $eligible = ($this->make)(['first_name' => 'Zqana', 'last_name' => 'Reyes']);
    ($this->make)(['first_name' => 'Zqanita', 'last_name' => 'Married', 'spouse_id' => ($this->make)()->id]);
    ($this->make)(['first_name' => 'Zqanabel', 'last_name' => 'Young', 'birthdate' => now()->subYears(10)->toDateString()]);
    ($this->make)(['first_name' => 'Zqanaida', 'last_name' => 'Sister', 'mother_id' => $resident->mother_id]);
    $other = Barangay::factory()->create();
    Resident::factory()->create(['first_name' => 'Zqanastasia', 'barangay_id' => $other->id, 'household_id' => Household::factory()->create(['barangay_id' => $other->id])->id, 'birthdate' => '1995-01-01']);

    $names = collect($this->actingAs($this->admin)->getJson(route('admin.residents.spouse-candidates', [$resident, 'q' => 'Zq']))->assertOk()->json('data'))->pluck('full_name')->all();

    expect($names)->toBe([$eligible->full_name]);
});

it('moves a resident to a new household without touching past claims', function () {
    $event = ReliefEvent::factory()->closed()->create(['municipality_id' => $this->barangay->municipality_id]);
    $resident = ($this->make)(['household_role' => 'Child']);
    $claim = ReliefLog::factory()->create(['event_id' => $event->id, 'resident_id' => $resident->id, 'household_id' => $this->household->id]);

    $this->actingAs($this->admin)->post(route('admin.residents.household', $resident), ['street_address' => '9 New Street', 'household_role' => 'Other', 'is_head' => true])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    $resident->refresh();
    $new = Household::query()->findOrFail($resident->household_id);

    expect($new->id)->not->toBe($this->household->id)
        ->and($new->street_address)->toBe('9 New Street')
        ->and($new->household_code)->toStartWith('HH-SJ-')
        ->and($new->head_resident_id)->toBe($resident->id)
        ->and($resident->household_role)->toBe('Head')
        ->and($claim->fresh()->household_id)->toBe($this->household->id)
        ->and($resident->changes()->first()->summary)->toContain('Moved from HH-SJ-0001', 'a new household');
});

it('moves a resident into an existing household in another barangay of the same admin', function () {
    $second = Barangay::factory()->create(['municipality_id' => $this->barangay->municipality_id, 'code' => 'CB']);
    $this->admin->managedBarangays()->syncWithoutDetaching([$second->id]);
    $target = Household::factory()->create(['barangay_id' => $second->id]);
    $resident = ($this->make)();

    $this->actingAs($this->admin)->post(route('admin.residents.household', $resident), ['household_id' => $target->id, 'household_role' => 'Spouse'])
        ->assertSessionHasNoErrors();

    expect($resident->fresh())->household_id->toBe($target->id)->barangay_id->toBe($second->id)->household_role->toBe('Spouse');
});

it('refuses household moves that do not make sense', function () {
    $resident = ($this->make)();
    $headed = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $headed->update(['head_resident_id' => ($this->make)(['household_id' => $headed->id])->id]);
    $outside = Household::factory()->create(['barangay_id' => Barangay::factory()]);

    $move = fn (array $payload) => $this->actingAs($this->admin)->post(route('admin.residents.household', $resident), $payload);

    $move(['household_id' => $this->household->id, 'household_role' => 'Child'])->assertSessionHasErrors('household_id');
    $move(['household_id' => $headed->id, 'is_head' => true])->assertSessionHasErrors('is_head');
    $move(['household_id' => $outside->id, 'household_role' => 'Child'])->assertSessionHasErrors('household_id');
    $move(['household_id' => $headed->id])->assertSessionHasErrors('household_role');
    $move(['household_role' => 'Child'])->assertSessionHasErrors('street_address');
    $move(['street_address' => '1 Test', 'household_role' => 'Head'])->assertSessionHasErrors('household_role');

    expect($resident->fresh()->household_id)->toBe($this->household->id);
});

it('asks for a new head when the head leaves a household that still has people', function () {
    $head = ($this->make)(['household_role' => 'Head']);
    $member = ($this->make)(['household_role' => 'Child']);
    $this->household->update(['head_resident_id' => $head->id]);
    $move = fn (array $extra = []) => $this->actingAs($this->admin)->post(route('admin.residents.household', $head), ['street_address' => '2 Elsewhere', 'household_role' => 'Other', ...$extra]);

    $move()->assertSessionHasErrors('new_head_id');
    $move(['new_head_id' => ($this->make)(['household_id' => Household::factory()->create(['barangay_id' => $this->barangay->id])->id])->id])->assertSessionHasErrors('new_head_id');
    expect($this->household->fresh()->head_resident_id)->toBe($head->id);

    $move(['new_head_id' => $member->id])->assertSessionHasNoErrors();

    expect($this->household->fresh()->head_resident_id)->toBe($member->id)
        ->and($member->fresh()->household_role)->toBe('Head');
});

it('lets the last person leave a household and leaves it without a head', function () {
    $only = ($this->make)(['household_role' => 'Head']);
    $this->household->update(['head_resident_id' => $only->id]);

    $this->actingAs($this->admin)->post(route('admin.residents.household', $only), ['street_address' => '3 Somewhere', 'household_role' => 'Other'])
        ->assertSessionHasNoErrors();

    expect($this->household->fresh()->head_resident_id)->toBeNull();
});

it('shows the resident\'s family, household, history and the relief events that pause household changes', function () {
    $event = ReliefEvent::factory()->create(['municipality_id' => $this->barangay->municipality_id, 'title' => 'Rice Aid']);
    $resident = ($this->make)(['first_name' => 'Miguel', 'last_name' => 'Cruz', 'gender' => 'Male']);
    ReliefLog::factory()->create(['event_id' => $event->id, 'resident_id' => $resident->id, 'household_id' => $this->household->id]);
    ResidentChange::factory()->create(['resident_id' => $resident->id, 'changed_by_user_id' => $this->admin->id, 'summary' => 'Earlier change']);

    $this->actingAs($this->admin)->get(route('admin.residents.show', $resident))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/resident')
            ->where('resident.full_name', 'Miguel Cruz')
            ->where('household.household_code', 'HH-SJ-0001')
            ->where('canMarry', true)
            ->where('openEvents', ['Rice Aid'])
            ->where('history.0.summary', 'Earlier change')
            ->where('history.0.changed_by', $this->admin->name));
});

it('keeps an account that recorded changes from being deleted', function () {
    ResidentChange::factory()->create(['changed_by_user_id' => $this->admin->id]);

    expect($this->admin->hasHistory())->toBeTrue();
});

it('does not allow household changes while a relief event is open where the resident lives', function () {
    $event = ReliefEvent::factory()->create(['municipality_id' => $this->barangay->municipality_id, 'title' => 'Rice Aid']);
    $resident = ($this->make)(['household_role' => 'Child']);

    $this->actingAs($this->admin)->post(route('admin.residents.household', $resident), ['street_address' => '9 New Street', 'household_role' => 'Other'])
        ->assertSessionHasErrors('household_id');

    expect(session('errors')->first('household_id'))->toContain('Rice Aid')
        ->and($resident->fresh()->household_id)->toBe($this->household->id)
        ->and(Household::query()->count())->toBe(1)
        ->and(ResidentChange::query()->count())->toBe(0);

    $event->update(['status' => EventStatus::Closed]);

    $this->actingAs($this->admin)->post(route('admin.residents.household', $resident), ['street_address' => '9 New Street', 'household_role' => 'Other'])
        ->assertSessionHasNoErrors();

    expect($resident->fresh()->household_id)->not->toBe($this->household->id);
});

it('only pauses household changes for events that are open right now in the same municipality', function (Closure $event, bool $blocked) {
    $event($this->barangay->municipality_id);
    $resident = ($this->make)();

    $this->actingAs($this->admin)->post(route('admin.residents.household', $resident), ['street_address' => '9 New Street', 'household_role' => 'Other']);

    expect($resident->fresh()->household_id === $this->household->id)->toBe($blocked);
})->with([
    'active and in its dates' => [fn (int $town) => ReliefEvent::factory()->create(['municipality_id' => $town]), true],
    'a draft' => [fn (int $town) => ReliefEvent::factory()->draft()->create(['municipality_id' => $town]), false],
    'closed' => [fn (int $town) => ReliefEvent::factory()->closed()->create(['municipality_id' => $town]), false],
    'not started yet' => [fn (int $town) => ReliefEvent::factory()->create(['municipality_id' => $town, 'start_date' => '2999-01-01', 'end_date' => '2999-02-01']), false],
    'already ended' => [fn (int $town) => ReliefEvent::factory()->create(['municipality_id' => $town, 'start_date' => '2000-01-01', 'end_date' => '2000-02-01']), false],
    'open in another municipality' => [fn (int $town) => ReliefEvent::factory()->create(['municipality_id' => Municipality::factory()->create()->id]), false],
]);

it('also pauses moves into a household in a municipality that has an open event', function () {
    $otherTown = Municipality::factory()->create();
    $second = Barangay::factory()->create(['municipality_id' => $otherTown->id]);
    $this->admin->managedBarangays()->syncWithoutDetaching([$second->id]);
    ReliefEvent::factory()->create(['municipality_id' => $otherTown->id, 'title' => 'Other Town Aid']);
    $target = Household::factory()->create(['barangay_id' => $second->id]);
    $resident = ($this->make)();

    $this->actingAs($this->admin)->post(route('admin.residents.household', $resident), ['household_id' => $target->id, 'household_role' => 'Child'])
        ->assertSessionHasErrors('household_id');

    expect($resident->fresh()->household_id)->toBe($this->household->id);
});

it('still lets a marriage be recorded while a relief event is open', function () {
    ReliefEvent::factory()->create(['municipality_id' => $this->barangay->municipality_id]);
    $groom = ($this->make)(['gender' => 'Male']);
    $bride = ($this->make)(['gender' => 'Female']);

    $this->actingAs($this->admin)->post(route('admin.residents.marriage', $groom), ['spouse_id' => $bride->id])->assertSessionHasNoErrors();

    expect($groom->fresh()->spouse_id)->toBe($bride->id);
});
