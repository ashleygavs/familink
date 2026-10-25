<?php

use App\Enums\RelativeKind;
use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\RelativeLink;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->withRole(RoleName::Admin)->create();
});

it('lists only pending residents', function () {
    $pending = Resident::factory()->pending()->create();
    Resident::factory()->create();

    $this->actingAs($this->admin)->get(route('admin.approvals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/approvals')
            ->has('pending.data', 1)
            ->where('pending.data.0.id', $pending->id));
});

it('approves a pending resident and records who approved', function () {
    $resident = Resident::factory()->pending()->create();

    $this->actingAs($this->admin)->post(route('admin.approvals.approve', $resident))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($resident->fresh()->status)->toBe(ResidentStatus::Approved)
        ->and($resident->fresh()->approved_by_user_id)->toBe($this->admin->id);
});

it('archives a pending resident without marking an approver', function () {
    $resident = Resident::factory()->pending()->create();

    $this->actingAs($this->admin)->post(route('admin.approvals.archive', $resident), ['reason' => 'Not a resident of this barangay.'])->assertRedirect();

    expect($resident->fresh()->status)->toBe(ResidentStatus::Archived)
        ->and($resident->fresh()->approved_by_user_id)->toBeNull();
});

it('does not allow deciding on a resident twice', function () {
    $resident = Resident::factory()->create();

    $this->actingAs($this->admin)->post(route('admin.approvals.archive', $resident), ['reason' => 'Not a resident of this barangay.'])->assertStatus(409);

    expect($resident->fresh()->status)->toBe(ResidentStatus::Approved);
});

it('keeps approvals away from encoders and stakeholders but open to the super admin', function () {
    $resident = Resident::factory()->pending()->create();

    foreach ([RoleName::User, RoleName::Stakeholder] as $role) {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->post(route('admin.approvals.approve', $resident))
            ->assertForbidden();
    }

    $this->actingAs(User::factory()->withRole(RoleName::SuperAdmin)->create())
        ->post(route('admin.approvals.approve', $resident))
        ->assertRedirect();

    expect($resident->fresh()->status)->toBe(ResidentStatus::Approved);
});

it('unlinks a parent, the spouse on both sides, a child and a named relative', function () {
    $father = Resident::factory()->create();
    $spouse = Resident::factory()->create();
    $resident = Resident::factory()->pending()->create(['father_id' => $father->id, 'spouse_id' => $spouse->id]);
    $spouse->update(['spouse_id' => $resident->id]);
    $child = Resident::factory()->create(['mother_id' => $resident->id]);
    $link = RelativeLink::create(['resident_id' => $resident->id, 'relative_id' => $father->id, 'relation' => RelativeKind::Cousin]);

    foreach ([['father', null], ['spouse', null], ['child', $child->id], ['relative', $link->id]] as [$kind, $id]) {
        $this->actingAs($this->admin)->post(route('admin.approvals.unlink', $resident), ['kind' => $kind, 'id' => $id])
            ->assertRedirect()->assertSessionHas('success');
    }

    expect($resident->fresh()->father_id)->toBeNull()
        ->and($resident->fresh()->spouse_id)->toBeNull()
        ->and($spouse->fresh()->spouse_id)->toBeNull()
        ->and($child->fresh()->mother_id)->toBeNull()
        ->and(RelativeLink::count())->toBe(0)
        ->and($father->fresh())->not->toBeNull();
});

it('does not unlink once the resident is no longer pending', function () {
    $father = Resident::factory()->create();
    $resident = Resident::factory()->create(['father_id' => $father->id]);

    $this->actingAs($this->admin)->post(route('admin.approvals.unlink', $resident), ['kind' => 'father'])->assertStatus(409);

    expect($resident->fresh()->father_id)->toBe($father->id);
});
