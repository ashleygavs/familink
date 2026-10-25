<?php

use App\Actions\Residents\RecordSuggestionFeedback;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Resident;
use App\Models\SuggestionFeedback;
use App\Models\User;
use App\Models\UserChange;
use Illuminate\Support\Facades\DB;

function queriesFor(callable $call): int
{
    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });
    $call();

    return $count;
}

beforeEach(function () {
    $this->municipality = Municipality::factory()->create();
    $this->barangay = Barangay::factory()->create(['municipality_id' => $this->municipality->id]);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $this->municipality->id]);
    $this->admin->managedBarangays()->sync([$this->barangay->id]);
});

it('asks the database the same number of questions however many staff are listed', function () {
    User::factory()->withRole(RoleName::User)->count(3)->create(['barangay_id' => $this->barangay->id]);
    $few = queriesFor(fn () => $this->actingAs($this->admin)->get(route('admin.users'))->assertOk());

    User::factory()->withRole(RoleName::User)->count(30)->create(['barangay_id' => $this->barangay->id]);
    $many = queriesFor(fn () => $this->actingAs($this->admin->fresh())->get(route('admin.users'))->assertOk());

    expect($many)->toBeLessThanOrEqual($few + 2);
});

it('still lets only an account with no history be deleted', function () {
    $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create();
    $clean = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);
    $busy = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);
    UserChange::factory()->create(['changed_by_user_id' => $busy->id]);

    $this->actingAs($superAdmin)->get(route('admin.users'))->assertInertia(fn ($page) => $page
        ->where('users', fn ($users) => collect($users)->firstWhere('id', $clean->id)['can_delete'] === true
            && collect($users)->firstWhere('id', $busy->id)['can_delete'] === false));

    expect($clean->hasHistory())->toBeFalse()->and($busy->hasHistory())->toBeTrue()
        ->and(User::idsWithHistory([$clean->id, $busy->id])->all())->toBe([$busy->id]);
});

it('keeps each resident\'s answered suggestions apart when asked for several at once', function () {
    $first = Resident::factory()->create(['barangay_id' => $this->barangay->id]);
    $second = Resident::factory()->create(['barangay_id' => $this->barangay->id]);
    $candidate = Resident::factory()->create(['barangay_id' => $this->barangay->id]);
    $other = Resident::factory()->create(['barangay_id' => $this->barangay->id]);

    foreach ([[$first, $candidate, 'father'], [$second, $other, 'mother'], [$second, $candidate, 'spouse']] as [$resident, $suggested, $role]) {
        SuggestionFeedback::query()->forceCreate([
            'resident_id' => $resident->id, 'candidate_id' => $suggested->id, 'role' => $role, 'decision' => 'rejected',
            'context' => 'approval', 'decided_by_user_id' => $this->admin->id,
        ]);
    }

    $answers = app(RecordSuggestionFeedback::class)->rejectedForMany([$first->id, $second->id, $other->id]);

    expect($answers[$first->id])->toEqual(['father' => [$candidate->id]])
        ->and($answers[$second->id])->toEqual(['mother' => [$other->id], 'spouse' => [$candidate->id]])
        ->and($answers)->not->toHaveKey($other->id)
        ->and(app(RecordSuggestionFeedback::class)->rejectedFor($first))->toBe(['father' => [$candidate->id]]);
});
