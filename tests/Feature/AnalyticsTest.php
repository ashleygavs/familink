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
    $this->sanJose = Barangay::factory()->create(['municipality_id' => $this->municipality->id, 'name' => 'San Jose']);
    $this->cabalantian = Barangay::factory()->create(['municipality_id' => $this->municipality->id, 'name' => 'Cabalantian']);
    $this->stakeholder = User::factory()->withRole(RoleName::Stakeholder)->create();

    $this->household = fn (Barangay $barangay, array $extra = []) => Household::factory()->create(['barangay_id' => $barangay->id, ...$extra]);
    $this->resident = fn (Household $household, array $extra = []) => Resident::factory()->create([
        'household_id' => $household->id, 'barangay_id' => $household->barangay_id, ...$extra,
    ]);
});

/**
 * The analytics page props for a user and query.
 *
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function analyticsFor(User $user, array $query = []): array
{
    $props = [];

    test()->actingAs($user)->get(route('reports.analytics', $query))->assertOk()
        ->assertInertia(function ($page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    return $props;
}

it('counts approved residents, households and pending registrations per barangay', function () {
    $home = ($this->household)($this->sanJose);
    ($this->resident)($home);
    ($this->resident)($home);
    ($this->resident)($home, ['status' => ResidentStatus::Pending]);
    ($this->resident)(($this->household)($this->cabalantian));

    $props = analyticsFor($this->stakeholder);
    $rows = collect($props['barangays'])->keyBy('name');

    expect($rows['San Jose'])->toMatchArray(['approved_residents' => 2, 'households' => 1])
        ->and($rows['Cabalantian']['approved_residents'])->toBe(1)
        ->and($props['totals'])->toMatchArray(['approved_residents' => 3, 'pending_residents' => 1, 'households' => 2])
        ->and($props['totals']['coverage'])->toBeNull();
});

it('measures coverage per household for a per household event', function () {
    $event = ReliefEvent::factory()->create(['municipality_id' => $this->municipality->id]);
    $claimed = ($this->household)($this->sanJose);
    $unclaimed = ($this->household)($this->sanJose);
    $first = ($this->resident)($claimed);
    ($this->resident)($claimed);
    ($this->resident)($unclaimed);
    ReliefLog::factory()->create(['event_id' => $event->id, 'resident_id' => $first->id, 'household_id' => $claimed->id]);

    $props = analyticsFor($this->stakeholder, ['event' => $event->id]);

    expect(collect($props['barangays'])->firstWhere('name', 'San Jose'))->toMatchArray(['eligible' => 2, 'claims' => 1, 'coverage' => 50])
        ->and($props['totals'])->toMatchArray(['eligible' => 2, 'claims' => 1, 'households_reached' => 1, 'coverage' => 50]);
});

it('measures coverage per resident for a per resident event', function () {
    $event = ReliefEvent::factory()->perResident()->create(['municipality_id' => $this->municipality->id]);
    $home = ($this->household)($this->sanJose);
    $people = collect(range(1, 4))->map(fn () => ($this->resident)($home));
    ReliefLog::factory()->create(['event_id' => $event->id, 'resident_id' => $people[0]->id, 'household_id' => $home->id]);

    expect(analyticsFor($this->stakeholder, ['event' => $event->id])['totals'])->toMatchArray(['eligible' => 4, 'claims' => 1, 'coverage' => 25]);
});

it('defaults to the active event, skips drafts and keeps coverage when a barangay has nobody eligible', function () {
    ReliefEvent::factory()->draft()->create(['municipality_id' => $this->municipality->id, 'title' => 'Draft one']);
    $active = ReliefEvent::factory()->create(['municipality_id' => $this->municipality->id, 'title' => 'Active one']);
    ($this->resident)(($this->household)($this->sanJose));

    $props = analyticsFor($this->stakeholder);

    expect($props['filters']['event'])->toBe($active->id)
        ->and(collect($props['events'])->pluck('title')->all())->toBe(['Active one'])
        ->and($props['totals']['coverage'])->toBe(0);
});

it('limits an admin to the barangays they handle and to their municipality events', function () {
    $admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $this->municipality->id]);
    $admin->managedBarangays()->sync([$this->sanJose->id]);
    ($this->resident)(($this->household)($this->sanJose));
    ($this->resident)(($this->household)($this->cabalantian));
    $foreignEvent = ReliefEvent::factory()->create(['municipality_id' => Municipality::factory()->create()->id]);

    $props = analyticsFor($admin);

    expect(collect($props['barangays'])->pluck('name')->all())->toBe(['San Jose'])
        ->and($props['totals']['approved_residents'])->toBe(1)
        ->and($props['events'])->toBe([]);

    $this->actingAs($admin)->get(route('reports.analytics', ['event' => $foreignEvent->id]))->assertSessionHasErrors('event');
});

it('lists households with their claim status and filters them', function () {
    $event = ReliefEvent::factory()->create(['municipality_id' => $this->municipality->id]);
    $claimedHome = ($this->household)($this->sanJose, ['household_code' => 'HH-SJ-0001', 'street_address' => '1 Rizal St']);
    $otherHome = ($this->household)($this->cabalantian, ['household_code' => 'HH-CAB-0001', 'street_address' => '9 Mabini St']);
    $head = ($this->resident)($claimedHome, ['first_name' => 'Pedro', 'last_name' => 'Santos']);
    $claimedHome->update(['head_resident_id' => $head->id]);
    ($this->resident)($otherHome);
    ReliefLog::factory()->create(['event_id' => $event->id, 'resident_id' => $head->id, 'household_id' => $claimedHome->id]);

    $all = analyticsFor($this->stakeholder)['households']['data'];
    $byCode = collect($all)->keyBy('household_code');

    expect($all)->toHaveCount(2)
        ->and($byCode['HH-SJ-0001'])->toMatchArray(['claimed' => true, 'head_name' => $head->full_name, 'members' => 1])
        ->and($byCode['HH-CAB-0001']['claimed'])->toBeFalse()
        ->and(analyticsFor($this->stakeholder, ['q' => 'santos'])['households']['data'])->toHaveCount(1)
        ->and(analyticsFor($this->stakeholder, ['q' => 'Mabini'])['households']['data'][0]['household_code'])->toBe('HH-CAB-0001')
        ->and(analyticsFor($this->stakeholder, ['barangay' => $this->sanJose->id])['households']['data'])->toHaveCount(1);
});

it('is open to stakeholders, admins and super admins but not encoders', function () {
    $this->actingAs(User::factory()->withRole(RoleName::User)->create())->get(route('reports.analytics'))->assertForbidden();

    foreach ([RoleName::Stakeholder, RoleName::Admin, RoleName::SuperAdmin] as $role) {
        $this->actingAs(User::factory()->withRole($role)->create())->get(route('reports.analytics'))->assertOk();
    }
});

it('counts at most the limit of residents per household as eligible', function () {
    $event = ReliefEvent::factory()->perResident(2)->create(['municipality_id' => $this->municipality->id]);
    $big = ($this->household)($this->sanJose);
    $small = ($this->household)($this->sanJose);
    collect(range(1, 5))->each(fn () => ($this->resident)($big));
    ($this->resident)($small);
    $first = Resident::query()->where('household_id', $big->id)->first();
    ReliefLog::factory()->create(['event_id' => $event->id, 'resident_id' => $first->id, 'household_id' => $big->id]);

    expect(analyticsFor($this->stakeholder, ['event' => $event->id])['totals'])->toMatchArray(['eligible' => 3, 'claims' => 1, 'coverage' => 33]);
});
