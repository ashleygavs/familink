<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use App\Models\SuggestionFeedback;
use App\Models\User;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['code' => 'SJ']);
    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create();

    $this->make = fn (string $first, string $gender, string $birthdate, array $extra = []) => Resident::factory()->create([
        'household_id' => $this->household->id,
        'barangay_id' => $this->barangay->id,
        'first_name' => $first,
        'middle_name' => null,
        'last_name' => 'Cariño',
        'gender' => $gender,
        'birthdate' => $birthdate,
        ...$extra,
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function feedbackEntry(int $candidateId, array $overrides = []): array
{
    return [
        'candidate_id' => $candidateId, 'role' => 'father', 'decision' => 'rejected',
        'score' => 60, 'confidence' => 'medium', 'reasons' => ['Same surname', 'About 22 years older'],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationWithFeedback(array $overrides = []): array
{
    return [
        'path' => 'existing', 'first_name' => 'Tomas', 'last_name' => 'Cariño', 'birthdate' => '2007-06-06',
        'gender' => 'Male', 'civil_status' => 'Single', 'household_mode' => 'new', 'street_address' => '1 Test St',
        'household_role' => 'Other', ...$overrides,
    ];
}

it('records every answer given while registering, tied to the new resident', function () {
    $dad = ($this->make)('Dad', 'Male', '1980-01-01');
    $uncle = ($this->make)('Uncle', 'Male', '1978-01-01');
    $maybe = ($this->make)('Maybe', 'Female', '1982-01-01');

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationWithFeedback([
        'father_id' => $dad->id,
        'suggestion_feedback' => [
            feedbackEntry($dad->id, ['decision' => 'accepted', 'confidence' => 'high', 'score' => 90]),
            feedbackEntry($uncle->id, ['decision' => 'reclassified', 'reclassified_as' => 'uncle_aunt']),
            feedbackEntry($maybe->id, ['role' => 'mother', 'decision' => 'unsure']),
        ],
    ]))->assertSessionHasNoErrors();

    $tomas = Resident::query()->where('first_name', 'Tomas')->firstOrFail();
    $rows = SuggestionFeedback::query()->where('resident_id', $tomas->id)->get()->keyBy('candidate_id');

    expect($rows)->toHaveCount(3)
        ->and($rows[$dad->id]->decision)->toBe('accepted')
        ->and($rows[$dad->id]->score)->toBe(90)
        ->and($rows[$dad->id]->context)->toBe('registration')
        ->and($rows[$dad->id]->decided_by_user_id)->toBe($this->encoder->id)
        ->and($rows[$uncle->id]->decision)->toBe('reclassified')
        ->and($rows[$uncle->id]->reclassified_as)->toBe('uncle_aunt')
        ->and($rows[$maybe->id]->role)->toBe('mother')
        ->and($rows[$dad->id]->reasons)->toBe(['Same surname', 'About 22 years older']);
});

it('keeps only the last answer for the same suggestion and never changes family links', function () {
    $dad = ($this->make)('Dad', 'Male', '1980-01-01');

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationWithFeedback([
        'suggestion_feedback' => [
            feedbackEntry($dad->id, ['decision' => 'unsure']),
            feedbackEntry($dad->id, ['decision' => 'rejected']),
        ],
    ]))->assertSessionHasNoErrors();

    $tomas = Resident::query()->where('first_name', 'Tomas')->firstOrFail();

    expect(SuggestionFeedback::query()->count())->toBe(1)
        ->and(SuggestionFeedback::query()->firstOrFail()->decision)->toBe('rejected')
        ->and($tomas->father_id)->toBeNull();
});

it('rejects invalid feedback without registering anyone', function (array $entry) {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), registrationWithFeedback([
        'suggestion_feedback' => [$entry],
    ]))->assertSessionHasErrors();

    expect(Resident::query()->where('first_name', 'Tomas')->exists())->toBeFalse();
})->with([
    'unknown role' => [['candidate_id' => 1, 'role' => 'neighbour', 'decision' => 'rejected']],
    'unknown decision' => [['candidate_id' => 1, 'role' => 'father', 'decision' => 'maybe']],
    'missing candidate' => [['candidate_id' => 999999, 'role' => 'father', 'decision' => 'rejected']],
    'bad reclassification' => [['candidate_id' => 1, 'role' => 'father', 'decision' => 'reclassified', 'reclassified_as' => 'friend']],
    'absurd score' => [['candidate_id' => 1, 'role' => 'father', 'decision' => 'accepted', 'score' => 9999]],
]);

it('lets an admin reject a suggestion and stops suggesting that person for the resident', function () {
    $dad = ($this->make)('Dad', 'Male', '1980-01-01');
    $resident = ($this->make)('Tomas', 'Male', '2007-06-06', ['status' => 'Pending']);

    $this->actingAs($this->admin)->get(route('admin.approvals'))
        ->assertInertia(fn ($page) => $page->where('pending.data.0.suggestions.father.0.resident.id', $dad->id));

    $this->actingAs($this->admin)->post(route('admin.approvals.feedback', $resident), [
        'suggestion_feedback' => [feedbackEntry($dad->id)],
    ])->assertRedirect();

    $this->actingAs($this->admin)->get(route('admin.approvals'))
        ->assertInertia(fn ($page) => $page->where('pending.data.0.suggestions.father', []));

    expect(SuggestionFeedback::query()->firstOrFail()->context)->toBe('approval')
        ->and($resident->fresh()->father_id)->toBeNull();
});

it('records the answer when an admin confirms a suggested link', function () {
    $dad = ($this->make)('Dad', 'Male', '1980-01-01');
    $resident = ($this->make)('Tomas', 'Male', '2007-06-06', ['status' => 'Pending']);

    $this->actingAs($this->admin)->post(route('admin.approvals.links', $resident), [
        'father_id' => $dad->id,
        'suggestion_feedback' => [feedbackEntry($dad->id, ['decision' => 'accepted', 'confidence' => 'high'])],
    ])->assertSessionHasNoErrors();

    $feedback = SuggestionFeedback::query()->firstOrFail();

    expect($resident->fresh()->father_id)->toBe($dad->id)
        ->and($feedback->decision)->toBe('accepted')
        ->and($feedback->context)->toBe('approval');
});

it('guards the approvals feedback endpoint', function () {
    $dad = ($this->make)('Dad', 'Male', '1980-01-01');
    $pending = ($this->make)('Tomas', 'Male', '2007-06-06', ['status' => 'Pending']);
    $approved = ($this->make)('Done', 'Male', '2007-06-06');

    $this->actingAs($this->encoder)->post(route('admin.approvals.feedback', $pending), ['suggestion_feedback' => [feedbackEntry($dad->id)]])->assertForbidden();
    $this->actingAs($this->admin)->post(route('admin.approvals.feedback', $approved), ['suggestion_feedback' => [feedbackEntry($dad->id)]])->assertStatus(409);
    $this->actingAs($this->admin)->post(route('admin.approvals.feedback', $pending), [])->assertSessionHasErrors('suggestion_feedback');

    expect(SuggestionFeedback::query()->count())->toBe(0);
});

it('reports how often each kind of suggestion is confirmed', function () {
    $people = collect(range(1, 6))->map(fn (int $n) => ($this->make)("Person{$n}", 'Male', '1980-01-0'.$n));
    $subject = ($this->make)('Subject', 'Male', '2007-06-06');

    $answer = fn (int $index, string $role, string $decision, array $extra = []) => SuggestionFeedback::query()->create([
        'resident_id' => $subject->id, 'candidate_id' => $people[$index]->id, 'role' => $role, 'decision' => $decision,
        'confidence' => 'high', 'reasons' => ['Same surname'], 'context' => 'registration', ...$extra,
    ]);

    $answer(0, 'father', 'accepted');
    $answer(1, 'father', 'rejected');
    $answer(2, 'father', 'rejected', ['reasons' => ['Same surname', 'Same barangay'], 'confidence' => 'medium']);
    $answer(3, 'father', 'unsure');
    $answer(4, 'father', 'reclassified', ['reclassified_as' => 'uncle_aunt']);
    $answer(5, 'cousin', 'accepted');

    $this->actingAs($this->admin)->get(route('admin.suggestion-report'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $props = $page->toArray()['props'];
            $father = collect($props['byRole'])->firstWhere('key', 'father');
            $reason = collect($props['byReason'])->firstWhere('key', 'Same surname');

            expect($props['totals'])->toMatchArray(['accepted' => 2, 'rejected' => 2, 'unsure' => 1, 'reclassified' => 1, 'all' => 6])
                ->and($father)->toMatchArray(['accepted' => 1, 'rejected' => 2, 'unsure' => 1, 'reclassified' => 1, 'rate' => 25])
                ->and($reason['total'])->toBe(6)
                ->and(collect($props['byRole'])->firstWhere('key', 'cousin')['rate'])->toBe(100)
                ->and(collect($props['byConfidence'])->firstWhere('key', 'medium')['rejected'])->toBe(1)
                ->and($props['reclassified'][0])->toMatchArray(['from' => 'Father', 'to' => 'Uncle or aunt', 'count' => 1]);
        });
});

it('shows an empty report and keeps it away from encoders and stakeholders', function () {
    $this->actingAs($this->admin)->get(route('admin.suggestion-report'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.all', 0)->where('byRole', []));

    $this->actingAs($this->encoder)->get(route('admin.suggestion-report'))->assertForbidden();
    $this->actingAs(User::factory()->withRole(RoleName::Stakeholder)->create())->get(route('admin.suggestion-report'))->assertForbidden();
    $this->actingAs(User::factory()->withRole(RoleName::SuperAdmin)->create())->get(route('admin.suggestion-report'))->assertOk();
});

/**
 * The "all answers" total the report shows for a user and query.
 *
 * @param  array<string, string>  $query
 */
function reportTotal(User $user, array $query = []): int
{
    $total = null;

    test()->actingAs($user)->get(route('admin.suggestion-report', $query))->assertOk()
        ->assertInertia(function ($page) use (&$total): void {
            $total = $page->toArray()['props']['totals']['all'];
        });

    return $total;
}

it('counts reasons that only differ by a number or a name as one', function () {
    $subject = ($this->make)('Subject', 'Male', '2005-01-01');

    foreach (['About 22 years older', 'About 31 years older', 'Parent of your cousin Marcus', 'Parent of your cousin Rica', 'Child of Pedro, your grandparent'] as $index => $reason) {
        SuggestionFeedback::query()->create([
            'resident_id' => $subject->id, 'candidate_id' => ($this->make)("Other{$index}", 'Male', '1980-01-01')->id, 'role' => 'uncle_aunt',
            'decision' => 'accepted', 'confidence' => 'high', 'reasons' => [$reason], 'context' => 'registration',
        ]);
    }

    $this->actingAs($this->admin)->get(route('admin.suggestion-report'))->assertInertia(function ($page) {
        $reasons = collect($page->toArray()['props']['byReason'])->keyBy('key');

        expect($reasons['Age gap fits (older)']['total'])->toBe(2)
            ->and($reasons['Parent of your cousin']['total'])->toBe(2)
            ->and($reasons['Child of your grandparent']['total'])->toBe(1)
            ->and($reasons)->not->toHaveKey('About 22 years older');
    });
});

it('limits the report to the chosen time range, defaulting to 90 days', function () {
    $subject = ($this->make)('Subject', 'Male', '2005-01-01');

    foreach (['10 days', '60 days', '200 days'] as $index => $ago) {
        SuggestionFeedback::query()->forceCreate([
            'resident_id' => $subject->id, 'candidate_id' => ($this->make)("Other{$index}", 'Male', '1980-01-01')->id, 'role' => 'father',
            'decision' => 'accepted', 'confidence' => 'high', 'reasons' => ['Same surname'], 'context' => 'registration',
            'created_at' => now()->sub($ago),
        ]);
    }

    expect(reportTotal($this->admin))->toBe(2)
        ->and(reportTotal($this->admin, ['range' => '30']))->toBe(1)
        ->and(reportTotal($this->admin, ['range' => 'all']))->toBe(3);
});

it('filters by municipality or barangay and never beyond what the viewer may see', function () {
    $other = Barangay::factory()->create();
    $mine = ($this->make)('Mine', 'Male', '2005-01-01');
    $foreign = Resident::factory()->create(['barangay_id' => $other->id, 'household_id' => Household::factory()->create(['barangay_id' => $other->id])->id]);

    foreach ([$mine, $foreign] as $index => $subject) {
        SuggestionFeedback::query()->create([
            'resident_id' => $subject->id, 'candidate_id' => ($this->make)("Other{$index}", 'Male', '1980-01-01')->id, 'role' => 'father',
            'decision' => 'accepted', 'confidence' => 'high', 'reasons' => ['Same surname'], 'context' => 'registration',
        ]);
    }

    $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create();

    expect(reportTotal($superAdmin))->toBe(2)
        ->and(reportTotal($superAdmin, ['place' => "b:{$other->id}"]))->toBe(1)
        ->and(reportTotal($superAdmin, ['place' => "m:{$this->barangay->municipality_id}"]))->toBe(1)
        ->and(reportTotal($this->admin, ['place' => "b:{$other->id}"]))->toBe(0);

    $this->actingAs($superAdmin)->get(route('admin.suggestion-report', ['range' => 'bad']))->assertSessionHasErrors('range');
});
