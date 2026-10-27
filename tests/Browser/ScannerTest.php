<?php

use App\Enums\ResidentStatus;
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
    $this->barangay = Barangay::factory()->create(['municipality_id' => $this->municipality->id, 'name' => 'San Jose', 'code' => 'SJ']);
    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);
    $this->event = ReliefEvent::factory()->create(['municipality_id' => $this->municipality->id, 'title' => 'Rice Distribution']);
    $this->resident = fn (array $extra = []) => Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id, 'status' => ResidentStatus::Approved, ...$extra,
    ]);
});

/*
 * One test on purpose: the scanner page keeps a camera request open, and ending a test with it still open stops a
 * second browser test in the same run from finishing. The steps are the same ones a person at the table would take.
 */
it('shows green the first time, red the second time, and red for a resident who cannot receive relief', function () {
    $resident = ($this->resident)();
    $waiting = ($this->resident)(['status' => ResidentStatus::Pending]);
    $this->actingAs($this->encoder);

    visit('/encoder/scanner')
        ->assertSee('Rice Distribution')
        ->type('#manual-code', $resident->system_id_no)
        ->press('Check this ID')
        ->assertSee('APPROVED')
        ->assertNoJavaScriptErrors();

    expect(ReliefLog::query()->where('resident_id', $resident->id)->count())->toBe(1);

    visit('/encoder/scanner')
        ->type('#manual-code', $resident->system_id_no)
        ->press('Check this ID')
        ->assertSee('NOT ALLOWED')
        ->assertNoJavaScriptErrors();

    visit('/encoder/scanner')
        ->type('#manual-code', $waiting->system_id_no)
        ->press('Check this ID')
        ->assertSee('NOT ALLOWED')
        ->assertNoJavaScriptErrors();

    visit('/encoder/scanner')
        ->type('#manual-code', 'FL-0000-XX-99999')
        ->press('Check this ID')
        ->assertSee('NOT ALLOWED')
        ->assertNoJavaScriptErrors();

    expect(ReliefLog::query()->count())->toBe(1);
});
