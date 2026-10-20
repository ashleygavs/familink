<?php

use App\Actions\Relief\CheckFamilyData;
use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Municipality;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $this->municipality = Municipality::factory()->create();
    $this->mine = Barangay::factory()->create(['municipality_id' => $this->municipality->id]);
    $this->other = Barangay::factory()->create(['municipality_id' => $this->municipality->id]);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $this->municipality->id]);
    $this->admin->managedBarangays()->sync([$this->mine->id]);
    $this->stakeholder = User::factory()->withRole(RoleName::Stakeholder)->create();
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id]);

    $this->household = Household::factory()->create(['barangay_id' => $this->mine->id]);
    $this->person = fn (array $extra = [], ?Household $household = null) => Resident::factory()->create([
        'household_id' => ($household ?? $this->household)->id, 'barangay_id' => ($household ?? $this->household)->barangay_id,
        'first_name' => fake()->unique()->firstName(), 'last_name' => 'Tester', 'middle_name' => null, 'suffix' => null,
        'birthdate' => now()->subYears(40), 'gender' => 'Male', ...$extra,
    ]);
    $this->check = fn (?User $user = null) => app(CheckFamilyData::class)->handle($user ?? $this->admin);
    $this->types = fn (array $result) => array_column($result['groups'], 'type');
});

it('finds nothing in healthy family records', function () {
    $father = ($this->person)(['birthdate' => now()->subYears(60)]);
    $mother = ($this->person)(['gender' => 'Female', 'birthdate' => now()->subYears(58), 'spouse_id' => $father->id]);
    $father->update(['spouse_id' => $mother->id]);
    ($this->person)(['father_id' => $father->id, 'mother_id' => $mother->id, 'birthdate' => now()->subYears(30)]);

    $result = ($this->check)();

    expect($result['groups'])->toBe([])->and($result['total'])->toBe(0)->and($result['scanned'])->toBe(3);
});

it('finds a person linked to themselves', function () {
    $person = ($this->person)();
    $person->update(['spouse_id' => $person->id, 'father_id' => $person->id]);

    $group = collect(($this->check)()['groups'])->firstWhere('type', 'own_link');

    expect($group['total'])->toBe(2)->and($group['problems'][0]['text'])->toContain('their own');
});

it('finds the same person as father and mother', function () {
    $parent = ($this->person)(['birthdate' => now()->subYears(60)]);
    ($this->person)(['father_id' => $parent->id, 'mother_id' => $parent->id]);

    expect(($this->types)(($this->check)()))->toContain('same_parent');
});

it('finds a father recorded as female and a mother recorded as male', function () {
    $woman = ($this->person)(['gender' => 'Female', 'birthdate' => now()->subYears(60)]);
    $man = ($this->person)(['gender' => 'Male', 'birthdate' => now()->subYears(60)]);
    ($this->person)(['father_id' => $woman->id, 'mother_id' => $man->id]);

    $group = collect(($this->check)()['groups'])->firstWhere('type', 'parent_gender');

    expect($group['total'])->toBe(2);
});

it('finds a parent born after, or too shortly before, their child', function () {
    $young = ($this->person)(['birthdate' => now()->subYears(25)]);
    ($this->person)(['father_id' => $young->id, 'birthdate' => now()->subYears(20)]);
    ($this->person)(['mother_id' => ($this->person)(['gender' => 'Female', 'birthdate' => now()->subYears(10)])->id, 'birthdate' => now()->subYears(20)]);

    $group = collect(($this->check)()['groups'])->firstWhere('type', 'parent_age');

    expect($group['total'])->toBe(2)
        ->and(collect($group['problems'])->pluck('text')->implode(' '))->toContain('born only 5 years before')->toContain('was born after them');
});

it('does not flag a parent who is old enough', function () {
    $parent = ($this->person)(['birthdate' => now()->subYears(32)]);
    ($this->person)(['father_id' => $parent->id, 'birthdate' => now()->subYears(20)]);

    expect(($this->types)(($this->check)()))->not->toContain('parent_age');
});

it('finds two people who are each other\'s parent', function () {
    $one = ($this->person)(['birthdate' => now()->subYears(50)]);
    $two = ($this->person)(['birthdate' => now()->subYears(20), 'father_id' => $one->id]);
    $one->update(['mother_id' => $two->id]);

    $group = collect(($this->check)()['groups'])->firstWhere('type', 'parent_loop');

    expect($group['total'])->toBe(1);
});

it('finds a marriage recorded on one side only and says whether the other is married to someone else', function () {
    $husband = ($this->person)();
    $wife = ($this->person)(['gender' => 'Female']);
    $husband->update(['spouse_id' => $wife->id]);

    $oneWay = collect(($this->check)()['groups'])->firstWhere('type', 'spouse_one_way');

    expect($oneWay['total'])->toBe(1)->and($oneWay['problems'][0]['text'])->toContain('has no spouse recorded');

    $wife->update(['spouse_id' => ($this->person)(['gender' => 'Male'])->id]);

    expect(collect(($this->check)()['groups'])->firstWhere('type', 'spouse_one_way')['problems'][0]['text'])->toContain('married to someone else');
});

it('finds a marriage to a parent, a child or a brother or sister', function () {
    $parent = ($this->person)(['birthdate' => now()->subYears(70)]);
    $child = ($this->person)(['father_id' => $parent->id, 'birthdate' => now()->subYears(40), 'spouse_id' => $parent->id]);
    $parent->update(['spouse_id' => $child->id]);

    $sibling = ($this->person)(['father_id' => $parent->id, 'birthdate' => now()->subYears(38)]);
    $sibling2 = ($this->person)(['father_id' => $parent->id, 'birthdate' => now()->subYears(36), 'spouse_id' => $sibling->id]);
    $sibling->update(['spouse_id' => $sibling2->id]);

    $group = collect(($this->check)()['groups'])->firstWhere('type', 'spouse_relative');

    expect($group['total'])->toBe(2)
        ->and(collect($group['problems'])->pluck('text')->implode(' '))->toContain('own child')->toContain('own brother or sister');
});

it('finds a birthdate in the future', function () {
    ($this->person)(['birthdate' => now()->addYear()]);

    expect(($this->types)(($this->check)()))->toContain('future_birth');
});

it('skips archived residents', function () {
    ($this->person)(['birthdate' => now()->addYear(), 'status' => ResidentStatus::Archived]);

    expect(($this->check)()['scanned'])->toBe(0)->and(($this->check)()['groups'])->toBe([]);
});

it('only looks at the barangays the viewer may see, but still judges links to people elsewhere', function () {
    $elsewhere = Household::factory()->create(['barangay_id' => $this->other->id]);
    ($this->person)(['birthdate' => now()->addYear()], $elsewhere);

    expect(($this->check)()['total'])->toBe(0)->and(($this->check)($this->stakeholder)['total'])->toBe(1);

    $remoteMother = ($this->person)(['gender' => 'Male', 'birthdate' => now()->subYears(60)], $elsewhere);
    ($this->person)(['mother_id' => $remoteMother->id]);

    expect(collect(($this->check)()['groups'])->firstWhere('type', 'parent_gender')['total'])->toBe(1);
});

it('lists only a limited number of problems per group but counts them all', function () {
    foreach (range(1, 55) as $unused) {
        ($this->person)(['birthdate' => now()->addYear()]);
    }

    $group = collect(($this->check)()['groups'])->firstWhere('type', 'future_birth');

    expect($group['total'])->toBe(55)->and($group['problems'])->toHaveCount(50);
});

it('shows the page to an admin and a stakeholder but not an encoder', function () {
    ($this->person)(['birthdate' => now()->addYear()]);

    $this->actingAs($this->admin)->get(route('reports.data-check'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('reports/data-check')->where('total', 1)->where('canFix', true)->has('groups', 1));
    $this->actingAs($this->stakeholder)->get(route('reports.data-check'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('canFix', false));
    $this->actingAs($this->encoder)->get(route('reports.data-check'))->assertForbidden();
});
