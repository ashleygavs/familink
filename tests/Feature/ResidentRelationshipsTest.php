<?php

use App\Enums\AllocationType;
use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Household;
use App\Models\ReliefEvent;
use App\Models\ReliefLog;
use App\Models\Resident;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;

it('links a resident to household, barangay and both parents and spouse', function () {
    $household = Household::factory()->create();
    $father = Resident::factory()->for($household)->create();
    $mother = Resident::factory()->for($household)->create(['spouse_id' => $father->id]);
    $child = Resident::factory()->for($household)->create([
        'father_id' => $father->id,
        'mother_id' => $mother->id,
    ]);

    expect($child->household->is($household))->toBeTrue()
        ->and($child->barangay_id)->toBe($household->barangay_id)
        ->and($child->father->is($father))->toBeTrue()
        ->and($child->mother->is($mother))->toBeTrue()
        ->and($mother->spouse->is($father))->toBeTrue()
        ->and($father->childrenAsFather)->toHaveCount(1)
        ->and($household->residents)->toHaveCount(3)
        ->and($child->status)->toBe(ResidentStatus::Approved);
});

it('assigns a role to a user and checks it', function () {
    $user = User::factory()->withRole(RoleName::Admin)->create();

    expect($user->role->name)->toBe('admin')
        ->and($user->hasRole(RoleName::Admin))->toBeTrue()
        ->and($user->hasRole('super_admin', RoleName::Stakeholder))->toBeFalse();
});

it('prevents the same resident from claiming the same event twice', function () {
    $event = ReliefEvent::factory()->create(['allocation_type' => AllocationType::PerResident]);
    $resident = Resident::factory()->create();

    ReliefLog::factory()->create(['event_id' => $event->id, 'resident_id' => $resident->id]);

    ReliefLog::factory()->create(['event_id' => $event->id, 'resident_id' => $resident->id]);
})->throws(UniqueConstraintViolationException::class);

it('seeds roles, barangays, one user per role and a linked test family', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->whereNotNull('role_id')->count())->toBe(4)
        ->and(User::query()->where('email', 'ashleyvenicegavino0728@gmail.com')->first()->hasRole(RoleName::SuperAdmin))->toBeTrue();

    $child = Resident::query()->where('system_id_no', 'FL-00000003')->firstOrFail();

    expect($child->father->first_name)->toBe('Juan')
        ->and($child->mother->first_name)->toBe('Maria')
        ->and($child->household->head->is($child->father))->toBeTrue();
});
