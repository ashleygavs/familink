<?php

use App\Enums\EventStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\ReliefEvent;
use App\Models\ReliefLog;
use App\Models\User;

beforeEach(function () {
    $this->municipality = Municipality::factory()->create();
    $this->barangay = Barangay::factory()->create(['municipality_id' => $this->municipality->id]);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $this->municipality->id]);
    $this->admin->managedBarangays()->sync([$this->barangay->id]);
    $this->superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create();
    $this->otherMunicipality = Municipality::factory()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function eventPayload(array $overrides = []): array
{
    return [
        'title' => 'Typhoon relief', 'municipality_id' => test()->municipality->id, 'allocation_type' => 'Per Household',
        'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'Draft', ...$overrides,
    ];
}

it('lets an admin create an event for their own municipality', function () {
    $this->actingAs($this->admin)->post(route('admin.events.store'), eventPayload())->assertSessionHasNoErrors();

    $event = ReliefEvent::query()->firstOrFail();

    expect($event->status)->toBe(EventStatus::Draft)
        ->and($event->created_by_user_id)->toBe($this->admin->id)
        ->and($event->municipality_id)->toBe($this->municipality->id);
});

it('stops an admin creating an event for another municipality', function () {
    $this->actingAs($this->admin)->post(route('admin.events.store'), eventPayload(['municipality_id' => $this->otherMunicipality->id]))
        ->assertSessionHasErrors('municipality_id');

    $this->actingAs($this->superAdmin)->post(route('admin.events.store'), eventPayload(['municipality_id' => $this->otherMunicipality->id]))
        ->assertSessionHasNoErrors();
});

it('validates dates, type and the starting status', function () {
    $this->actingAs($this->admin)->post(route('admin.events.store'), eventPayload(['end_date' => '2026-09-01']))->assertSessionHasErrors('end_date');
    $this->actingAs($this->admin)->post(route('admin.events.store'), eventPayload(['allocation_type' => 'Everyone']))->assertSessionHasErrors('allocation_type');
    $this->actingAs($this->admin)->post(route('admin.events.store'), eventPayload(['status' => 'Closed']))->assertSessionHasErrors('status');
});

it('only lists the events of the admin\'s municipality', function () {
    ReliefEvent::factory()->create(['title' => 'Mine', 'municipality_id' => $this->municipality->id]);
    ReliefEvent::factory()->create(['title' => 'Elsewhere', 'municipality_id' => $this->otherMunicipality->id]);

    $this->actingAs($this->admin)->get(route('admin.events'))
        ->assertInertia(fn ($page) => $page->has('events', 1)->where('events.0.title', 'Mine'));
    $this->actingAs($this->superAdmin)->get(route('admin.events'))->assertInertia(fn ($page) => $page->has('events', 2));
});

it('moves an event forward but never back, and a closed event is locked', function () {
    $event = ReliefEvent::factory()->draft()->create(['municipality_id' => $this->municipality->id]);
    $payload = fn (string $status) => eventPayload(['title' => $event->title, 'status' => $status]);

    $this->actingAs($this->admin)->put(route('admin.events.update', $event), $payload('Active'))->assertSessionHasNoErrors();
    expect($event->fresh()->status)->toBe(EventStatus::Active);

    $this->actingAs($this->admin)->put(route('admin.events.update', $event), $payload('Draft'))->assertSessionHasErrors('status');

    $this->actingAs($this->admin)->put(route('admin.events.update', $event), $payload('Closed'))->assertSessionHasNoErrors();
    expect($event->fresh()->status)->toBe(EventStatus::Closed);

    $this->actingAs($this->admin)->put(route('admin.events.update', $event), $payload('Active'))->assertForbidden();
});

it('only lets an active event change its title, end date and status', function () {
    $event = ReliefEvent::factory()->create(['municipality_id' => $this->municipality->id, 'allocation_type' => 'Per Household', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);

    $this->actingAs($this->admin)->put(route('admin.events.update', $event), eventPayload([
        'title' => 'Renamed', 'allocation_type' => 'Per Resident', 'start_date' => '2026-09-01', 'end_date' => '2026-11-15', 'status' => 'Active',
    ]))->assertSessionHasNoErrors();

    $fresh = $event->fresh();

    expect($fresh->title)->toBe('Renamed')
        ->and($fresh->end_date->toDateString())->toBe('2026-11-15')
        ->and($fresh->allocation_type->value)->toBe('Per Household')
        ->and($fresh->start_date->toDateString())->toBe('2026-10-01');
});

it('stops an admin changing or deleting another municipality\'s event', function () {
    $foreign = ReliefEvent::factory()->draft()->create(['municipality_id' => $this->otherMunicipality->id]);

    $this->actingAs($this->admin)->put(route('admin.events.update', $foreign), eventPayload())->assertForbidden();
    $this->actingAs($this->admin)->delete(route('admin.events.destroy', $foreign))->assertForbidden();
});

it('deletes only a draft that nobody has claimed from', function () {
    $draft = ReliefEvent::factory()->draft()->create(['municipality_id' => $this->municipality->id]);
    $active = ReliefEvent::factory()->create(['municipality_id' => $this->municipality->id]);

    $this->actingAs($this->admin)->delete(route('admin.events.destroy', $active))->assertSessionHasErrors('event');
    $this->actingAs($this->admin)->delete(route('admin.events.destroy', $draft))->assertSessionHasNoErrors();

    expect(ReliefEvent::query()->find($draft->id))->toBeNull()->and(ReliefEvent::query()->find($active->id))->not->toBeNull();

    $claimed = ReliefEvent::factory()->draft()->create(['municipality_id' => $this->municipality->id]);
    ReliefLog::factory()->create(['event_id' => $claimed->id]);

    $this->actingAs($this->admin)->delete(route('admin.events.destroy', $claimed))->assertSessionHasErrors('event');
});

it('keeps event management away from encoders and stakeholders', function () {
    foreach ([RoleName::User, RoleName::Stakeholder] as $role) {
        $this->actingAs(User::factory()->withRole($role)->create())->get(route('admin.events'))->assertForbidden();
    }
});

it('lets only a super admin set the claim limit per household', function () {
    $payload = eventPayload(['allocation_type' => 'Per Resident', 'max_claims_per_household' => 3]);

    $this->actingAs($this->admin)->post(route('admin.events.store'), $payload)->assertSessionHasNoErrors();
    expect(ReliefEvent::query()->latest('id')->first()->max_claims_per_household)->toBeNull();

    $this->actingAs($this->superAdmin)->post(route('admin.events.store'), $payload)->assertSessionHasNoErrors();
    expect(ReliefEvent::query()->latest('id')->first()->max_claims_per_household)->toBe(3);

    $this->actingAs($this->superAdmin)->post(route('admin.events.store'), eventPayload(['max_claims_per_household' => 0]))->assertSessionHasErrors('max_claims_per_household');
});

it('ignores the limit on a per household event', function () {
    $this->actingAs($this->superAdmin)->post(route('admin.events.store'), eventPayload(['allocation_type' => 'Per Household', 'max_claims_per_household' => 4]))->assertSessionHasNoErrors();

    expect(ReliefEvent::query()->latest('id')->first())->max_claims_per_household->toBeNull()->claimLimitPerHousehold()->toBe(1);
});

it('lets a super admin change the limit of an active event while an admin cannot', function () {
    $event = ReliefEvent::factory()->perResident(2)->create(['municipality_id' => $this->municipality->id]);
    $payload = eventPayload(['title' => $event->title, 'allocation_type' => 'Per Resident', 'status' => 'Active', 'max_claims_per_household' => 5]);

    $this->actingAs($this->admin)->put(route('admin.events.update', $event), $payload)->assertSessionHasNoErrors();
    expect($event->fresh()->max_claims_per_household)->toBe(2);

    $this->actingAs($this->superAdmin)->put(route('admin.events.update', $event), $payload)->assertSessionHasNoErrors();
    expect($event->fresh()->max_claims_per_household)->toBe(5);

    $this->actingAs($this->superAdmin)->put(route('admin.events.update', $event), [...$payload, 'max_claims_per_household' => ''])->assertSessionHasNoErrors();
    expect($event->fresh()->max_claims_per_household)->toBeNull();
});

it('tells the page whether the viewer may set the limit', function () {
    $this->actingAs($this->admin)->get(route('admin.events'))->assertInertia(fn ($page) => $page->where('canSetLimit', false));
    $this->actingAs($this->superAdmin)->get(route('admin.events'))->assertInertia(fn ($page) => $page->where('canSetLimit', true));
});
