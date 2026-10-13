<?php

use App\Enums\ResidentStatus;
use App\Enums\RoleName;
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

    $this->actingAs($this->admin)->post(route('admin.approvals.archive', $resident))->assertRedirect();

    expect($resident->fresh()->status)->toBe(ResidentStatus::Archived)
        ->and($resident->fresh()->approved_by_user_id)->toBeNull();
});

it('does not allow deciding on a resident twice', function () {
    $resident = Resident::factory()->create();

    $this->actingAs($this->admin)->post(route('admin.approvals.archive', $resident))->assertStatus(409);

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
