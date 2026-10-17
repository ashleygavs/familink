<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Municipality;
use App\Models\ReliefEvent;
use App\Models\ReliefLog;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $this->municipality = Municipality::factory()->create();
    $this->barangay = Barangay::factory()->create(['municipality_id' => $this->municipality->id]);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);
    $this->event = ReliefEvent::factory()->perResident()->create(['municipality_id' => $this->municipality->id]);

    $this->resident = fn () => Resident::factory()->create([
        'household_id' => Household::factory()->create(['barangay_id' => $this->barangay->id])->id, 'barangay_id' => $this->barangay->id,
    ]);
    $this->scan = fn (string $code, array $extra = []) => $this->actingAs($this->encoder)
        ->postJson(route('encoder.scan'), ['event_id' => $this->event->id, 'code' => $code, ...$extra]);
    $this->represented = fn (string $name = 'Rosa Dela Cruz', array $extra = []) => [
        'representative' => true, 'representative_name' => $name, 'representative_relationship' => 'Child', 'representative_verified' => true, ...$extra,
    ];
});

it('records who collected the relief for the resident', function () {
    $resident = ($this->resident)();

    ($this->scan)($resident->qr_code_hash, ($this->represented)())->assertOk()
        ->assertJson(['granted' => true, 'warning' => null])
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'collected by Rosa Dela Cruz (Child)'));

    $log = ReliefLog::query()->firstOrFail();

    expect($log->claimed_by_representative)->toBeTrue()
        ->and($log->representative_name)->toBe('Rosa Dela Cruz')
        ->and($log->representative_relationship)->toBe('Child')
        ->and($log->representative_verified)->toBeTrue()
        ->and($log->entry_method)->toBe('qr');
});

it('records a claim by the resident themselves as no representative', function () {
    $resident = ($this->resident)();

    ($this->scan)($resident->qr_code_hash)->assertJson(['granted' => true]);

    expect(ReliefLog::query()->firstOrFail())
        ->claimed_by_representative->toBeFalse()->representative_name->toBeNull()->representative_verified->toBeFalse();
});

it('records whether the QR code or a typed ID was used', function () {
    $byQr = ($this->resident)();
    $byId = ($this->resident)();

    ($this->scan)($byQr->qr_code_hash);
    ($this->scan)($byId->system_id_no);

    expect(ReliefLog::query()->where('resident_id', $byQr->id)->value('entry_method'))->toBe('qr')
        ->and(ReliefLog::query()->where('resident_id', $byId->id)->value('entry_method'))->toBe('manual');
});

it('needs a name, a relationship and the confirmation when someone else collects', function (array $change, string $field) {
    $resident = ($this->resident)();

    ($this->scan)($resident->qr_code_hash, ($this->represented)('Rosa Dela Cruz', $change))->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(ReliefLog::query()->count())->toBe(0);
})->with([
    'no name' => [['representative_name' => ''], 'representative_name'],
    'a one-letter name' => [['representative_name' => 'R'], 'representative_name'],
    'no relationship' => [['representative_relationship' => ''], 'representative_relationship'],
    'unknown relationship' => [['representative_relationship' => 'Boss'], 'representative_relationship'],
    'authorization not confirmed' => [['representative_verified' => false], 'representative_verified'],
]);

it('shows who collected an earlier claim when a second try is refused', function () {
    $resident = ($this->resident)();

    ($this->scan)($resident->qr_code_hash, ($this->represented)());
    ($this->scan)($resident->qr_code_hash)->assertJson(['granted' => false, 'code' => 'already_claimed', 'previous' => ['collected_by' => 'Rosa Dela Cruz']]);
});

it('warns when the same person keeps collecting for other residents', function () {
    foreach (range(1, 3) as $time) {
        $response = ($this->scan)(($this->resident)()->qr_code_hash, ($this->represented)($time === 2 ? '  rosa dela cruz ' : 'Rosa Dela Cruz'));

        $response->assertJson(['granted' => true]);

        if ($time < 3) {
            $response->assertJson(['warning' => null]);
        }
    }

    ($this->scan)(($this->resident)()->qr_code_hash, ($this->represented)())
        ->assertJson(['granted' => true])
        ->assertJsonPath('warning', fn (string $warning) => str_contains($warning, 'already collected for 3 other residents'));

    ($this->scan)(($this->resident)()->qr_code_hash, ($this->represented)('Someone Else'))->assertJson(['warning' => null]);
});
