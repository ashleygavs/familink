<?php

use App\Actions\Relief\FindSplitHouseholds;
use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\HouseholdReview;
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
    $this->event = ReliefEvent::factory()->create(['municipality_id' => $this->municipality->id]);

    $this->household = fn (Barangay $barangay) => Household::factory()->create(['barangay_id' => $barangay->id]);
    $this->person = fn (Household $household, array $extra = []) => Resident::factory()->create([
        'household_id' => $household->id, 'barangay_id' => $household->barangay_id, 'middle_name' => null, 'suffix' => null, ...$extra,
    ]);
    $this->married = function (Household $one, Household $two) {
        $husband = ($this->person)($one, ['first_name' => 'Juan', 'last_name' => 'Cruz', 'gender' => 'Male']);
        $wife = ($this->person)($two, ['first_name' => 'Maria', 'last_name' => 'Cruz', 'gender' => 'Female', 'spouse_id' => $husband->id]);
        $husband->update(['spouse_id' => $wife->id]);

        return [$husband, $wife];
    };
    $this->find = fn (?array $scope = null, ?ReliefEvent $event = null) => app(FindSplitHouseholds::class)->handle($scope, $event);
});

it('lists a married couple recorded in two households', function () {
    $first = ($this->household)($this->mine);
    $second = ($this->household)($this->mine);
    ($this->married)($first, $second);

    $cases = ($this->find)(null);

    expect($cases)->toHaveCount(1)
        ->and($cases[0]['households'][0]['household_code'])->toBe($first->household_code)
        ->and($cases[0]['households'][1]['household_code'])->toBe($second->household_code)
        ->and($cases[0]['reasons'])->toBe(['Juan Cruz and Maria Cruz are married but live in different households.'])
        ->and($cases[0]['both_claimed'])->toBeNull()
        ->and($cases[0]['review'])->toBeNull();
});

it('finds a couple once even when only one of them has the link recorded', function () {
    $husband = ($this->person)(($this->household)($this->mine), ['first_name' => 'Juan', 'last_name' => 'Cruz']);
    ($this->person)(($this->household)($this->mine), ['first_name' => 'Maria', 'last_name' => 'Cruz'])->update(['spouse_id' => $husband->id]);

    expect(($this->find)(null))->toHaveCount(1)
        ->and(($this->find)(null)[0]['reasons'])->toHaveCount(1);
});

it('does not list a couple living in the same household', function () {
    $home = ($this->household)($this->mine);
    ($this->married)($home, $home);

    expect(($this->find)(null))->toBe([]);
});

it('lists a young child living apart from a parent and names which parent', function () {
    $parentHome = ($this->household)($this->mine);
    $childHome = ($this->household)($this->mine);
    $mother = ($this->person)($parentHome, ['first_name' => 'Rosa', 'last_name' => 'Reyes']);
    ($this->person)($childHome, ['first_name' => 'Pedro', 'last_name' => 'Reyes', 'mother_id' => $mother->id, 'birthdate' => now()->subYears(7)]);

    $cases = ($this->find)(null);

    expect($cases)->toHaveCount(1)
        ->and($cases[0]['reasons'])->toBe(['Pedro Reyes, age 7, lives apart from their mother, Rosa Reyes.']);
});

it('does not expect an adult child to live with a parent', function () {
    $mother = ($this->person)(($this->household)($this->mine));
    ($this->person)(($this->household)($this->mine), ['mother_id' => $mother->id, 'birthdate' => now()->subYears(30)]);

    expect(($this->find)(null))->toBe([]);
});

it('joins several reasons for the same two households into one case', function () {
    $first = ($this->household)($this->mine);
    $second = ($this->household)($this->mine);
    [$husband] = ($this->married)($first, $second);
    ($this->person)($second, ['father_id' => $husband->id, 'birthdate' => now()->subYears(5)]);

    $cases = ($this->find)(null);

    expect($cases)->toHaveCount(1)->and($cases[0]['reasons'])->toHaveCount(2);
});

it('ignores people who cannot claim relief', function () {
    $husband = ($this->person)(($this->household)($this->mine));
    $wife = ($this->person)(($this->household)($this->mine), ['spouse_id' => $husband->id, 'status' => ResidentStatus::Pending]);
    $husband->update(['spouse_id' => $wife->id]);

    expect(($this->find)(null))->toBe([]);

    $wife->update(['status' => ResidentStatus::Approved, 'is_deceased' => true]);

    expect(($this->find)(null))->toBe([]);
});

it('says when both households claimed in the chosen event', function () {
    $first = ($this->household)($this->mine);
    $second = ($this->household)($this->mine);
    [$husband, $wife] = ($this->married)($first, $second);

    foreach ([$husband, $wife] as $resident) {
        ReliefLog::factory()->create([
            'event_id' => $this->event->id, 'resident_id' => $resident->id, 'household_id' => $resident->household_id,
            'scanned_by_user_id' => $this->encoder->id,
        ]);
    }

    $case = ($this->find)(null, $this->event)[0];

    expect($case['both_claimed'])->toBeTrue()
        ->and($case['households'][0]['claims'])->toBe(1)
        ->and($case['households'][1]['claims'])->toBe(1);

    ReliefLog::query()->where('resident_id', $wife->id)->delete();

    expect(($this->find)(null, $this->event)[0]['both_claimed'])->toBeFalse();
});

it('lists pairs claiming twice before the others', function () {
    $quiet = ($this->household)($this->mine);
    ($this->married)($quiet, ($this->household)($this->mine));
    [$husband, $wife] = ($this->married)(($this->household)($this->mine), ($this->household)($this->mine));

    foreach ([$husband, $wife] as $resident) {
        ReliefLog::factory()->create([
            'event_id' => $this->event->id, 'resident_id' => $resident->id, 'household_id' => $resident->household_id,
            'scanned_by_user_id' => $this->encoder->id,
        ]);
    }

    $cases = ($this->find)(null, $this->event);

    expect($cases)->toHaveCount(2)->and($cases[0]['both_claimed'])->toBeTrue()->and($cases[1]['both_claimed'])->toBeFalse();
});

it('shows a pair to an admin who works in only one of its barangays, without the other side\'s head', function () {
    $here = ($this->household)($this->mine);
    $there = ($this->household)($this->other);
    $there->update(['head_resident_id' => ($this->person)($there, ['first_name' => 'Secret', 'last_name' => 'Head'])->id]);
    ($this->married)($here, $there);

    $case = ($this->find)([$this->mine->id])[0];
    $sides = collect($case['households'])->keyBy('id');

    expect($sides[$here->id]['can_open'])->toBeTrue()
        ->and($sides[$there->id]['can_open'])->toBeFalse()
        ->and($sides[$there->id]['head_name'])->toBeNull()
        ->and($sides[$there->id]['household_code'])->toBe($there->household_code);
});

it('hides pairs entirely outside the viewer\'s barangays', function () {
    ($this->married)(($this->household)($this->other), ($this->household)($this->other));

    expect(($this->find)([$this->mine->id]))->toBe([]);
});

it('shows the page to an admin, a stakeholder and a super admin but not an encoder', function () {
    ($this->married)(($this->household)($this->mine), ($this->household)($this->mine));

    $this->actingAs($this->admin)->get(route('reports.household-review'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('reports/household-review')
            ->where('counts.open', 1)->where('canReview', true)->has('cases', 1)->where('filters.event', $this->event->id));

    $this->actingAs($this->stakeholder)->get(route('reports.household-review'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('canReview', false));

    $this->actingAs(User::factory()->withRole(RoleName::SuperAdmin)->create())->get(route('reports.household-review'))->assertOk();
    $this->actingAs($this->encoder)->get(route('reports.household-review'))->assertForbidden();
});

it('rejects an event the viewer cannot see', function () {
    $draft = ReliefEvent::factory()->draft()->create(['municipality_id' => $this->municipality->id]);

    $this->actingAs($this->admin)->get(route('reports.household-review', ['event' => $draft->id]))->assertSessionHasErrors('event');
});

it('keeps a reviewed pair off the list until asked for', function () {
    $first = ($this->household)($this->mine);
    $second = ($this->household)($this->mine);
    ($this->married)($first, $second);

    $this->actingAs($this->admin)->post(route('admin.household-reviews.store'), [
        'first_household_id' => $second->id, 'second_household_id' => $first->id, 'reason' => 'They work in different towns.',
    ])->assertRedirect()->assertSessionHas('success');

    $review = HouseholdReview::query()->sole();

    expect($review->first_household_id)->toBe(min($first->id, $second->id))
        ->and($review->reviewed_by_user_id)->toBe($this->admin->id);

    $this->actingAs($this->admin)->get(route('reports.household-review'))
        ->assertInertia(fn ($page) => $page->where('counts.open', 0)->where('counts.reviewed', 1)->has('cases', 0));

    $this->actingAs($this->admin)->get(route('reports.household-review', ['reviewed' => 1]))
        ->assertInertia(fn ($page) => $page->has('cases', 1)->where('cases.0.review.reason', 'They work in different towns.'));
});

it('updates the reason instead of reviewing the same pair twice', function () {
    $first = ($this->household)($this->mine);
    $second = ($this->household)($this->mine);

    foreach (['First reason given.', 'Second reason given.'] as $reason) {
        $this->actingAs($this->admin)->post(route('admin.household-reviews.store'), [
            'first_household_id' => $first->id, 'second_household_id' => $second->id, 'reason' => $reason,
        ])->assertRedirect();
    }

    expect(HouseholdReview::query()->count())->toBe(1)->and(HouseholdReview::query()->sole()->reason)->toBe('Second reason given.');
});

it('needs a reason, two different households and access to one of them', function () {
    $first = ($this->household)($this->mine);
    $second = ($this->household)($this->mine);
    $elsewhere = ($this->household)($this->other);
    $alsoElsewhere = ($this->household)($this->other);

    $this->actingAs($this->admin)->post(route('admin.household-reviews.store'), [
        'first_household_id' => $first->id, 'second_household_id' => $second->id, 'reason' => '',
    ])->assertSessionHasErrors('reason');

    $this->actingAs($this->admin)->post(route('admin.household-reviews.store'), [
        'first_household_id' => $first->id, 'second_household_id' => $first->id, 'reason' => 'Same one twice.',
    ])->assertSessionHasErrors('second_household_id');

    $this->actingAs($this->admin)->post(route('admin.household-reviews.store'), [
        'first_household_id' => $elsewhere->id, 'second_household_id' => $alsoElsewhere->id, 'reason' => 'Not my barangay.',
    ])->assertNotFound();

    expect(HouseholdReview::query()->count())->toBe(0);
});

it('does not let a stakeholder or an encoder mark a pair as reviewed', function () {
    $first = ($this->household)($this->mine);
    $second = ($this->household)($this->mine);
    $payload = ['first_household_id' => $first->id, 'second_household_id' => $second->id, 'reason' => 'Looks fine to me.'];

    $this->actingAs($this->stakeholder)->post(route('admin.household-reviews.store'), $payload)->assertForbidden();
    $this->actingAs($this->encoder)->post(route('admin.household-reviews.store'), $payload)->assertForbidden();

    expect(HouseholdReview::query()->count())->toBe(0);
});

it('puts a reviewed pair back on the list', function () {
    $first = ($this->household)($this->mine);
    $second = ($this->household)($this->mine);
    ($this->married)($first, $second);
    $review = HouseholdReview::factory()->create([...HouseholdReview::pair($first->id, $second->id), 'reviewed_by_user_id' => $this->admin->id]);

    $this->actingAs($this->admin)->delete(route('admin.household-reviews.destroy', $review))->assertRedirect();

    expect(HouseholdReview::query()->count())->toBe(0);
    $this->actingAs($this->admin)->get(route('reports.household-review'))->assertInertia(fn ($page) => $page->where('counts.open', 1));
});

it('does not let an admin reopen a pair from other barangays', function () {
    $review = HouseholdReview::factory()->create([
        ...HouseholdReview::pair(($this->household)($this->other)->id, ($this->household)($this->other)->id),
    ]);

    $this->actingAs($this->admin)->delete(route('admin.household-reviews.destroy', $review))->assertNotFound();

    expect(HouseholdReview::query()->count())->toBe(1);
});
