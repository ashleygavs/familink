<?php

use App\Enums\CardReplacementReason;
use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Municipality;
use App\Models\ReliefEvent;
use App\Models\ReliefLog;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\RetiredQrCode;
use App\Models\User;

beforeEach(function () {
    $this->municipality = Municipality::factory()->create();
    $this->barangay = Barangay::factory()->create(['municipality_id' => $this->municipality->id]);
    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $this->municipality->id]);
    $this->admin->managedBarangays()->sync([$this->barangay->id]);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);
    $this->event = ReliefEvent::factory()->perResident()->create(['municipality_id' => $this->municipality->id]);
    $this->resident = Resident::factory()->create(['household_id' => $this->household->id, 'barangay_id' => $this->barangay->id]);

    $this->replace = fn (string $reason = 'Lost', ?Resident $resident = null) => $this->actingAs($this->admin)
        ->post(route('admin.residents.card.replace', $resident ?? $this->resident), ['reason' => $reason]);
    $this->scan = fn (string $code, ?User $user = null) => $this->actingAs($user ?? $this->encoder)
        ->postJson(route('encoder.scan'), ['event_id' => $this->event->id, 'code' => $code]);
});

it('replaces a QR card, refuses the old code and accepts the new one', function () {
    $old = $this->resident->qr_code_hash;

    ($this->replace)('Lost')->assertSessionHasNoErrors()->assertSessionHas('success');

    $new = $this->resident->fresh()->qr_code_hash;

    expect($new)->not->toBe($old)
        ->and($this->resident->fresh()->system_id_no)->toBe($this->resident->system_id_no)
        ->and(RetiredQrCode::query()->where('qr_code_hash', $old)->value('reason'))->toBe(CardReplacementReason::Lost)
        ->and($this->resident->changes()->where('type', ResidentChange::CARD_REPLACED)->first()->changed_by_user_id)->toBe($this->admin->id);

    ($this->scan)($old)->assertOk()->assertJson(['granted' => false, 'code' => 'card_replaced'])
        ->assertJsonPath('message', fn (string $message) => str_contains($message, $this->resident->full_name) && str_contains($message, 'Lost'));
    ($this->scan)($new)->assertJson(['granted' => true]);

    expect(ReliefLog::query()->count())->toBe(1);
});

it('counts every try of the old code', function () {
    $old = $this->resident->qr_code_hash;
    ($this->replace)('Stolen');

    ($this->scan)($old);
    ($this->scan)($old);

    $retired = RetiredQrCode::query()->firstOrFail();

    expect($retired->attempts)->toBe(2)->and($retired->last_attempt_at)->not->toBeNull();
    $this->actingAs($this->admin)->get(route('admin.residents.show', $this->resident))
        ->assertInertia(fn ($page) => $page->has('retiredCards', 1)->where('retiredCards.0.attempts', 2)->where('retiredCards.0.reason', 'Stolen'));
});

it('does not name the resident to an encoder from another area', function () {
    $old = $this->resident->qr_code_hash;
    ($this->replace)('Lost');
    $outsider = User::factory()->withRole(RoleName::User)->create([
        'barangay_id' => Barangay::factory()->create(['municipality_id' => $this->municipality->id])->id,
    ]);

    ($this->scan)($old, $outsider)->assertJson(['granted' => false, 'code' => 'card_replaced', 'resident' => null, 'message' => 'This QR card is no longer valid.']);

    expect(RetiredQrCode::query()->firstOrFail()->attempts)->toBe(1);
});

it('stops the resident ID being typed after a lost or stolen card, but not a damaged one', function (string $reason, bool $blocked) {
    ($this->replace)($reason);

    ($this->scan)($this->resident->system_id_no)->assertJson($blocked ? ['granted' => false, 'code' => 'manual_blocked'] : ['granted' => true]);
    ($this->scan)($this->resident->fresh()->qr_code_hash)->assertJson($blocked ? ['granted' => true] : ['granted' => false, 'code' => 'already_claimed']);
})->with([
    'lost' => ['Lost', true],
    'stolen' => ['Stolen', true],
    'damaged' => ['Damaged', false],
]);

it('still lets another resident type their ID', function () {
    ($this->replace)('Lost');
    $other = Resident::factory()->create(['household_id' => $this->household->id, 'barangay_id' => $this->barangay->id]);

    ($this->scan)($other->system_id_no)->assertJson(['granted' => true]);
});

it('keeps earlier claims after the card is replaced', function () {
    ($this->scan)($this->resident->qr_code_hash)->assertJson(['granted' => true]);
    ($this->replace)('Lost');

    ($this->scan)($this->resident->fresh()->qr_code_hash)->assertJson(['granted' => false, 'code' => 'already_claimed']);
    expect(ReliefLog::query()->count())->toBe(1);
});

it('only replaces the card of an approved, living resident in the admin\'s barangays', function () {
    $pending = Resident::factory()->create(['barangay_id' => $this->barangay->id, 'status' => ResidentStatus::Pending]);
    $deceased = Resident::factory()->create(['barangay_id' => $this->barangay->id, 'is_deceased' => true]);

    ($this->replace)('Lost', $pending)->assertSessionHasErrors('reason');
    ($this->replace)('Lost', $deceased)->assertSessionHasErrors('reason');
    ($this->replace)('Gone')->assertSessionHasErrors('reason');

    $outsider = Resident::factory()->create(['barangay_id' => Barangay::factory()->create()->id]);
    ($this->replace)('Lost', $outsider)->assertNotFound();
    $this->actingAs($this->encoder)->post(route('admin.residents.card.replace', $this->resident), ['reason' => 'Lost'])->assertForbidden();

    expect(RetiredQrCode::query()->count())->toBe(0);
});
