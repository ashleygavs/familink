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
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->municipality = Municipality::factory()->create();
    $this->barangay = Barangay::factory()->create(['municipality_id' => $this->municipality->id]);
    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);
    $this->event = ReliefEvent::factory()->create(['municipality_id' => $this->municipality->id]);

    $this->resident = fn (array $extra = []) => Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id, ...$extra,
    ]);
    $this->scan = fn (string $code, ?ReliefEvent $event = null, ?User $user = null) => $this->actingAs($user ?? $this->encoder)
        ->postJson(route('encoder.scan'), ['event_id' => ($event ?? $this->event)->id, 'code' => $code]);
});

it('grants relief to an approved resident and records the claim', function () {
    $resident = ($this->resident)();

    ($this->scan)($resident->qr_code_hash)->assertOk()
        ->assertJson(['granted' => true, 'code' => 'granted', 'resident' => ['system_id_no' => $resident->system_id_no]]);

    $log = ReliefLog::query()->firstOrFail();

    expect($log->event_id)->toBe($this->event->id)
        ->and($log->resident_id)->toBe($resident->id)
        ->and($log->household_id)->toBe($this->household->id)
        ->and($log->scanned_by_user_id)->toBe($this->encoder->id);
});

it('accepts a typed resident ID when the QR code is damaged', function () {
    $resident = ($this->resident)();

    ($this->scan)(strtolower($resident->system_id_no))->assertJson(['granted' => true]);
});

it('denies a code that is not registered', function () {
    ($this->scan)('does-not-exist')->assertOk()->assertJson(['granted' => false, 'code' => 'unknown_code', 'resident' => null]);

    expect(ReliefLog::query()->count())->toBe(0);
});

it('denies residents who are not approved, archived or deceased', function (string $state, string $code) {
    $resident = ($this->resident)(match ($state) {
        'pending' => ['status' => ResidentStatus::Pending],
        'listed' => ['status' => ResidentStatus::Listed],
        'archived' => ['status' => ResidentStatus::Archived],
        'deceased' => ['is_deceased' => true],
    });

    ($this->scan)($resident->qr_code_hash)->assertJson(['granted' => false, 'code' => $code]);

    expect(ReliefLog::query()->count())->toBe(0);
})->with([
    'pending' => ['pending', 'not_approved'],
    'listed' => ['listed', 'not_approved'],
    'archived' => ['archived', 'not_approved'],
    'deceased' => ['deceased', 'deceased'],
]);

it('lets a household claim once when the event is per household', function () {
    $first = ($this->resident)();
    $second = ($this->resident)();

    ($this->scan)($first->qr_code_hash)->assertJson(['granted' => true]);
    $response = ($this->scan)($second->qr_code_hash)->assertJson(['granted' => false, 'code' => 'household_claimed']);

    expect($response->json('previous.name'))->toBe($first->full_name)
        ->and($response->json('previous.scanned_by'))->toBe($this->encoder->name)
        ->and(ReliefLog::query()->count())->toBe(1);
});

it('lets each household member claim once when the event is per resident', function () {
    $event = ReliefEvent::factory()->perResident()->create(['municipality_id' => $this->municipality->id]);
    $first = ($this->resident)();
    $second = ($this->resident)();

    ($this->scan)($first->qr_code_hash, $event)->assertJson(['granted' => true]);
    ($this->scan)($second->qr_code_hash, $event)->assertJson(['granted' => true]);
    ($this->scan)($first->qr_code_hash, $event)->assertJson(['granted' => false, 'code' => 'already_claimed']);

    expect(ReliefLog::query()->count())->toBe(2);
});

it('treats the same resident in a different event as a fresh claim', function () {
    $resident = ($this->resident)();
    $other = ReliefEvent::factory()->create(['municipality_id' => $this->municipality->id]);

    ($this->scan)($resident->qr_code_hash)->assertJson(['granted' => true]);
    ($this->scan)($resident->qr_code_hash, $other)->assertJson(['granted' => true]);
});

it('only scans events that are active and inside their dates', function (array $state) {
    $event = ReliefEvent::factory()->create(['municipality_id' => $this->municipality->id, ...$state]);
    $resident = ($this->resident)();

    ($this->scan)($resident->qr_code_hash, $event)->assertJson(['granted' => false, 'code' => 'event_closed']);

    expect(ReliefLog::query()->count())->toBe(0);
})->with([
    'draft' => [['status' => EventStatus::Draft]],
    'closed' => [['status' => EventStatus::Closed]],
    'not started' => [['start_date' => '2999-01-01', 'end_date' => '2999-02-01']],
    'ended' => [['start_date' => '2000-01-01', 'end_date' => '2000-02-01']],
]);

it('denies residents of another barangay or municipality', function () {
    $otherBarangay = Barangay::factory()->create(['municipality_id' => $this->municipality->id]);
    $sameTownOtherBarangay = ($this->resident)(['barangay_id' => $otherBarangay->id, 'household_id' => Household::factory()->create(['barangay_id' => $otherBarangay->id])->id]);

    ($this->scan)($sameTownOtherBarangay->qr_code_hash)->assertJson(['granted' => false, 'code' => 'outside_area']);

    $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create();
    $otherTown = Barangay::factory()->create();
    $foreign = Resident::factory()->create(['barangay_id' => $otherTown->id, 'household_id' => Household::factory()->create(['barangay_id' => $otherTown->id])->id]);

    ($this->scan)($foreign->qr_code_hash, null, $superAdmin)->assertJson(['granted' => false, 'code' => 'outside_area']);
    expect(ReliefLog::query()->count())->toBe(0);
});

it('refuses events from another municipality and invalid requests', function () {
    $foreignEvent = ReliefEvent::factory()->create(['municipality_id' => Municipality::factory()->create()->id]);
    $resident = ($this->resident)();

    ($this->scan)($resident->qr_code_hash, $foreignEvent)->assertUnprocessable()->assertJsonValidationErrors('event_id');
    $this->actingAs($this->encoder)->postJson(route('encoder.scan'), ['event_id' => $this->event->id])->assertUnprocessable()->assertJsonValidationErrors('code');
});

it('keeps scanning to encoders', function () {
    $stakeholder = User::factory()->withRole(RoleName::Stakeholder)->create();
    $admin = User::factory()->withRole(RoleName::Admin)->create();
    $resident = ($this->resident)();

    foreach ([$stakeholder, $admin] as $user) {
        ($this->scan)($resident->qr_code_hash, null, $user)->assertForbidden();
    }

});

it('requires a login to scan', function () {
    $this->postJson(route('encoder.scan'), [])->assertUnauthorized();
});

it('backs up the per resident check with the unique index when two scans race', function () {
    $event = ReliefEvent::factory()->perResident()->create(['municipality_id' => $this->municipality->id]);
    $resident = ($this->resident)();

    ReliefLog::factory()->create(['event_id' => $event->id, 'resident_id' => $resident->id, 'household_id' => $this->household->id]);

    expect(fn () => ReliefLog::factory()->create(['event_id' => $event->id, 'resident_id' => $resident->id, 'household_id' => $this->household->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('lists only the events an encoder can scan right now on the scanner screen', function () {
    $open = ReliefEvent::factory()->create(['municipality_id' => $this->municipality->id, 'title' => 'Open now']);
    ReliefEvent::factory()->draft()->create(['municipality_id' => $this->municipality->id]);
    ReliefEvent::factory()->closed()->create(['municipality_id' => $this->municipality->id]);
    ReliefEvent::factory()->create(['municipality_id' => $this->municipality->id, 'start_date' => '2999-01-01', 'end_date' => '2999-02-01']);
    ReliefEvent::factory()->create(['municipality_id' => Municipality::factory()->create()->id]);

    $this->actingAs($this->encoder)->get(route('encoder.scanner'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('encoder/scanner')->has('events', 2)->where('events.0.id', $this->event->id)->where('events.1.id', $open->id));
});

it('stops a household at the claim limit of a per resident event', function () {
    $event = ReliefEvent::factory()->perResident(2)->create(['municipality_id' => $this->municipality->id]);
    $members = collect(range(1, 3))->map(fn () => ($this->resident)());

    ($this->scan)($members[0]->qr_code_hash, $event)->assertJson(['granted' => true]);
    ($this->scan)($members[1]->qr_code_hash, $event)->assertJson(['granted' => true]);
    ($this->scan)($members[2]->qr_code_hash, $event)->assertJson(['granted' => false, 'code' => 'household_limit']);
    ($this->scan)($members[0]->qr_code_hash, $event)->assertJson(['granted' => false, 'code' => 'already_claimed']);

    $otherHousehold = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $neighbour = Resident::factory()->create(['household_id' => $otherHousehold->id, 'barangay_id' => $this->barangay->id]);
    ($this->scan)($neighbour->qr_code_hash, $event)->assertJson(['granted' => true]);

    expect(ReliefLog::query()->where('household_id', $this->household->id)->count())->toBe(2);
});

it('has no household limit when none is set', function () {
    $event = ReliefEvent::factory()->perResident()->create(['municipality_id' => $this->municipality->id]);

    foreach (range(1, 5) as $ignored) {
        ($this->scan)(($this->resident)()->qr_code_hash, $event)->assertJson(['granted' => true]);
    }
});
