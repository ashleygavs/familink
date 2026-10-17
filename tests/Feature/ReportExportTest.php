<?php

use App\Actions\Relief\BuildClaimsReport;
use App\Actions\Relief\BuildDistributionList;
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
    $this->mine = Barangay::factory()->create(['municipality_id' => $this->municipality->id, 'name' => 'San Jose']);
    $this->other = Barangay::factory()->create(['municipality_id' => $this->municipality->id, 'name' => 'Cabalantian']);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $this->municipality->id]);
    $this->admin->managedBarangays()->sync([$this->mine->id]);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id]);
    $this->stakeholder = User::factory()->withRole(RoleName::Stakeholder)->create();
    $this->superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create();
    $this->event = ReliefEvent::factory()->perResident()->create(['municipality_id' => $this->municipality->id, 'title' => 'Typhoon Aid']);

    $this->household = fn (Barangay $barangay) => Household::factory()->create(['barangay_id' => $barangay->id]);
    $this->person = fn (Household $household, array $extra = []) => Resident::factory()->create([
        'household_id' => $household->id, 'barangay_id' => $household->barangay_id, 'middle_name' => null, 'suffix' => null, ...$extra,
    ]);
    $this->claim = fn (Resident $resident, array $extra = []) => ReliefLog::factory()->create([
        'event_id' => $this->event->id, 'resident_id' => $resident->id, 'household_id' => $resident->household_id,
        'scanned_by_user_id' => $this->encoder->id, ...$extra,
    ]);
    $this->rows = fn ($response) => array_map('str_getcsv', array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF"))));
});

it('downloads the claims of an event with who collected them', function () {
    $own = ($this->person)(($this->household)($this->mine), ['first_name' => 'Ana', 'last_name' => 'Reyes']);
    $byProxy = ($this->person)(($this->household)($this->mine), ['first_name' => 'Ben', 'last_name' => 'Cruz']);
    ($this->claim)($own);
    ($this->claim)($byProxy, [
        'claimed_by_representative' => true, 'representative_name' => 'Rosa Cruz', 'representative_relationship' => 'Child',
        'representative_verified' => true, 'entry_method' => 'manual',
    ]);

    $response = $this->actingAs($this->admin)->get(route('reports.claims-csv', $this->event))->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->headers->get('content-disposition'))->toContain('typhoon-aid-claims-')
        ->and($response->streamedContent())->toStartWith("\xEF\xBB\xBF");

    $rows = ($this->rows)($response);

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBe(BuildClaimsReport::HEADERS)
        ->and($rows[1][5])->toBe('Ana Reyes')->and($rows[1][6])->toBe('QR code')->and($rows[1][7])->toBe('The resident')
        ->and($rows[2][5])->toBe('Ben Cruz')->and($rows[2][6])->toBe('Typed resident ID')->and($rows[2][7])->toBe('Rosa Cruz')
        ->and($rows[2][8])->toBe('Child')->and($rows[2][9])->toBe('Yes');
});

it('limits the claims to the barangays the viewer may see', function () {
    ($this->claim)(($this->person)(($this->household)($this->mine), ['first_name' => 'Ana', 'last_name' => 'Reyes']));
    ($this->claim)(($this->person)(($this->household)($this->other), ['first_name' => 'Eli', 'last_name' => 'Tan']));

    expect(($this->rows)($this->actingAs($this->admin)->get(route('reports.claims-csv', $this->event))))->toHaveCount(2);
    expect(($this->rows)($this->actingAs($this->superAdmin)->get(route('reports.claims-csv', $this->event))))->toHaveCount(3);
    expect(($this->rows)($this->actingAs($this->stakeholder)->get(route('reports.claims-csv', $this->event))))->toHaveCount(3);
});

it('keeps a typed name from running as a spreadsheet formula', function () {
    ($this->claim)(($this->person)(($this->household)($this->mine)), [
        'claimed_by_representative' => true, 'representative_name' => '=HYPERLINK("http://evil.test","click")',
        'representative_relationship' => 'Other', 'representative_verified' => true,
    ]);

    $rows = ($this->rows)($this->actingAs($this->admin)->get(route('reports.claims-csv', $this->event)));

    expect($rows[1][7])->toStartWith("'=HYPERLINK");
});

it('downloads the same coverage figures as the analytics page', function () {
    $first = ($this->person)(($this->household)($this->mine));
    ($this->person)(($this->household)($this->mine));
    ($this->claim)($first);

    $rows = ($this->rows)($this->actingAs($this->admin)->get(route('reports.coverage-csv', $this->event)));

    expect($rows[0][1])->toBe('Barangay')
        ->and($rows[1][1])->toBe('San Jose')
        ->and($rows[1][4])->toBe('2')->and($rows[1][5])->toBe('1')->and($rows[1][8])->toBe('50%');
    $this->actingAs($this->admin)->get(route('reports.analytics', ['event' => $this->event->id]))
        ->assertInertia(fn ($page) => $page->where('totals.coverage', 50));
});

it('keeps the claim exports away from encoders and other municipalities', function () {
    $foreign = ReliefEvent::factory()->create(['municipality_id' => Municipality::factory()->create()->id]);

    $this->actingAs($this->encoder)->get(route('reports.claims-csv', $this->event))->assertForbidden();
    $this->actingAs($this->encoder)->get(route('reports.coverage-csv', $this->event))->assertForbidden();
    $this->actingAs($this->admin)->get(route('reports.claims-csv', $foreign))->assertForbidden();
    $this->actingAs($this->admin)->get(route('reports.coverage-csv', $foreign))->assertForbidden();
});

it('lists who can claim in a barangay, grouped by household, and who already has', function () {
    $family = ($this->household)($this->mine);
    $claimed = ($this->person)($family, ['first_name' => 'Ana', 'last_name' => 'Reyes']);
    ($this->person)($family, ['first_name' => 'Ben', 'last_name' => 'Reyes']);
    ($this->person)($family, ['first_name' => 'Pat', 'last_name' => 'Pending', 'status' => ResidentStatus::Pending]);
    ($this->person)($family, ['first_name' => 'Dan', 'last_name' => 'Dead', 'is_deceased' => true]);
    ($this->person)(($this->household)($this->other), ['first_name' => 'Eli', 'last_name' => 'Elsewhere']);
    ($this->claim)($claimed, ['claimed_by_representative' => true, 'representative_name' => 'Rosa Reyes', 'representative_relationship' => 'Child', 'representative_verified' => true]);

    $this->actingAs($this->encoder)->get(route('reports.distribution-list'))
        ->assertInertia(fn ($page) => $page
            ->where('barangay', 'San Jose')
            ->where('list.totals', ['households' => 1, 'members' => 2, 'claimed' => 1])
            ->where('list.households.0.members.0.name', 'Ana Reyes')
            ->where('list.households.0.members.0.claimed', true)
            ->where('list.households.0.members.0.collected_by', 'Rosa Reyes')
            ->where('list.households.0.members.1.claimed', false));
});

it('can show only those who have not claimed yet', function () {
    $family = ($this->household)($this->mine);
    ($this->claim)(($this->person)($family, ['first_name' => 'Ana', 'last_name' => 'Reyes']));
    ($this->person)($family, ['first_name' => 'Ben', 'last_name' => 'Reyes']);

    $this->actingAs($this->encoder)->get(route('reports.distribution-list', ['only_unclaimed' => 1]))
        ->assertInertia(fn ($page) => $page->where('list.totals.members', 1)->where('list.households.0.members.0.name', 'Ben Reyes'));
});

it('lists a household once for a per household event and marks a reached limit', function () {
    $perHousehold = ReliefEvent::factory()->create(['municipality_id' => $this->municipality->id]);
    $limited = ReliefEvent::factory()->perResident(1)->create(['municipality_id' => $this->municipality->id]);
    $family = ($this->household)($this->mine);
    $first = ($this->person)($family, ['first_name' => 'Ana', 'last_name' => 'Reyes']);
    ($this->person)($family, ['first_name' => 'Ben', 'last_name' => 'Reyes']);
    ReliefLog::factory()->create(['event_id' => $perHousehold->id, 'resident_id' => $first->id, 'household_id' => $family->id, 'scanned_by_user_id' => $this->encoder->id]);
    ReliefLog::factory()->create(['event_id' => $limited->id, 'resident_id' => $first->id, 'household_id' => $family->id, 'scanned_by_user_id' => $this->encoder->id]);

    $this->actingAs($this->encoder)->get(route('reports.distribution-list', ['event' => $perHousehold->id]))
        ->assertInertia(fn ($page) => $page->where('list.households.0.claimed', true)->where('list.households.0.members.1.claimed', true));
    $this->actingAs($this->encoder)->get(route('reports.distribution-list', ['event' => $limited->id]))
        ->assertInertia(fn ($page) => $page->where('list.households.0.limit_reached', true)->where('list.households.0.members.1.claimed', false));
    $this->actingAs($this->encoder)->get(route('reports.distribution-list', ['event' => $limited->id, 'only_unclaimed' => 1]))
        ->assertInertia(fn ($page) => $page->where('list.totals.members', 0));
});

it('keeps an encoder to their own barangay and leaves out draft events', function () {
    ($this->person)(($this->household)($this->other), ['first_name' => 'Eli', 'last_name' => 'Elsewhere']);
    $draft = ReliefEvent::factory()->draft()->create(['municipality_id' => $this->municipality->id]);

    $this->actingAs($this->encoder)->get(route('reports.distribution-list', ['barangay' => $this->other->id]))
        ->assertInertia(fn ($page) => $page->where('barangay', 'San Jose')->has('barangays', 1));
    $this->actingAs($this->encoder)->get(route('reports.distribution-list', ['event' => $draft->id]))->assertSessionHasErrors('event');
    $this->actingAs($this->superAdmin)->get(route('reports.distribution-list', ['barangay' => $this->other->id]))
        ->assertInertia(fn ($page) => $page->where('barangay', 'Cabalantian')->has('barangays', 2));
});

it('downloads the distribution list with a signature column', function () {
    $family = ($this->household)($this->mine);
    ($this->person)($family, ['first_name' => 'Ana', 'last_name' => 'Reyes']);

    $response = $this->actingAs($this->encoder)->get(route('reports.distribution-list.csv'))->assertOk();
    $rows = ($this->rows)($response);

    expect($response->headers->get('content-disposition'))->toContain('san-jose')
        ->and($rows[0])->toBe(BuildDistributionList::HEADERS)
        ->and($rows[1][4])->toBe('Ana Reyes')->and($rows[1][6])->toBe('No')->and(end($rows[1]))->toBe('');
});

it('keeps the printable list to encoders and admins', function () {
    $this->actingAs($this->stakeholder)->get(route('reports.distribution-list'))->assertForbidden();
    $this->actingAs($this->stakeholder)->get(route('reports.distribution-list.csv'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('reports.distribution-list'))->assertOk();
});
