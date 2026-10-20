<?php

use App\Actions\Relief\FindRelationship;
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

    $this->person = fn (string $first, array $extra = [], ?Barangay $barangay = null) => Resident::factory()->create([
        'household_id' => Household::factory()->create(['barangay_id' => ($barangay ?? $this->mine)->id])->id,
        'barangay_id' => ($barangay ?? $this->mine)->id,
        'first_name' => $first, 'last_name' => 'Cruz', 'middle_name' => null, 'suffix' => null, 'gender' => 'Male', ...$extra,
    ]);
    $this->find = fn (Resident $first, Resident $second) => app(FindRelationship::class)->handle($first, $second);

    // Grandparents Lola and Lolo, their children Ana and Ben, and a child of each: Cora (Ana's) and Dan (Ben's).
    $this->lolo = ($this->person)('Lolo');
    $this->lola = ($this->person)('Lola', ['gender' => 'Female']);
    $this->ana = ($this->person)('Ana', ['gender' => 'Female', 'father_id' => $this->lolo->id, 'mother_id' => $this->lola->id]);
    $this->ben = ($this->person)('Ben', ['father_id' => $this->lolo->id, 'mother_id' => $this->lola->id]);
    $this->cora = ($this->person)('Cora', ['gender' => 'Female', 'mother_id' => $this->ana->id]);
    $this->dan = ($this->person)('Dan', ['father_id' => $this->ben->id]);
});

it('says what two cousins are to each other, from both sides', function () {
    expect(($this->find)($this->cora, $this->dan))->toBe(['second_is' => 'First cousin', 'first_is' => 'First cousin']);
});

it('says aunt and niece the right way round', function () {
    expect(($this->find)($this->cora, $this->ben))->toBe(['second_is' => 'Uncle', 'first_is' => 'Niece'])
        ->and(($this->find)($this->lolo, $this->cora))->toBe(['second_is' => 'Granddaughter', 'first_is' => 'Grandfather']);
});

it('finds relatives who live in different barangays', function () {
    $far = ($this->person)('Far', ['father_id' => $this->ben->id], $this->other);

    expect(($this->find)($this->cora, $far)['second_is'])->toBe('First cousin');
});

it('describes relatives by marriage through the spouse', function () {
    $wife = ($this->person)('Wendy', ['gender' => 'Female', 'spouse_id' => $this->ben->id]);
    $this->ben->update(['spouse_id' => $wife->id]);

    expect(($this->find)($this->cora, $wife))->toBe(['second_is' => 'Wife of uncle', 'first_is' => 'Spouse\'s niece']);
});

it('finds nothing for people who are not related', function () {
    $stranger = ($this->person)('Stranger');

    expect(($this->find)($this->cora, $stranger))->toBe(['second_is' => null, 'first_is' => null]);
});

it('follows a family tree many generations up', function () {
    $top = ($this->person)('Top');
    $previous = $top;

    foreach (range(1, 8) as $generation) {
        $previous = ($this->person)("Gen{$generation}", ['father_id' => $previous->id]);
    }

    expect(($this->find)($top, $previous)['second_is'])->toBe('Great-great-great-great-great-great-grandson');
});

it('shows the answer on the page for an admin and a stakeholder', function () {
    $this->actingAs($this->admin)->get(route('reports.relationship', ['first' => $this->cora->id, 'second' => $this->dan->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('reports/relationship')
            ->where('first.name', 'Cora Cruz')->where('second.name', 'Dan Cruz')
            ->where('result.related', true)
            ->where('result.second_is', 'Dan Cruz is Cora Cruz\'s first cousin.')
            ->where('result.first_is', 'Cora Cruz is Dan Cruz\'s first cousin.'));

    $this->actingAs($this->stakeholder)->get(route('reports.relationship', ['first' => $this->cora->id, 'second' => $this->dan->id]))->assertOk();
});

it('says so on the page when there is no link', function () {
    $stranger = ($this->person)('Stranger');

    $this->actingAs($this->admin)->get(route('reports.relationship', ['first' => $this->cora->id, 'second' => $stranger->id]))
        ->assertInertia(fn ($page) => $page->where('result.related', false)->where('result.second_is', null));
});

it('shows the empty form when no one is chosen, and rejects choosing the same person twice', function () {
    $this->actingAs($this->admin)->get(route('reports.relationship'))
        ->assertInertia(fn ($page) => $page->where('first', null)->where('second', null)->where('result', null));

    $this->actingAs($this->admin)->get(route('reports.relationship', ['first' => $this->ana->id, 'second' => $this->ana->id]))
        ->assertSessionHasErrors('second');
});

it('does not let an admin look up a resident from another barangay', function () {
    $far = ($this->person)('Far', [], $this->other);

    $this->actingAs($this->admin)->get(route('reports.relationship', ['first' => $this->cora->id, 'second' => $far->id]))->assertForbidden();
    $this->actingAs($this->stakeholder)->get(route('reports.relationship', ['first' => $this->cora->id, 'second' => $far->id]))->assertOk();
});

it('does not let an encoder use it', function () {
    $this->actingAs($this->encoder)->get(route('reports.relationship'))->assertForbidden();
    $this->actingAs($this->encoder)->getJson(route('reports.relationship.search', ['q' => 'Cora']))->assertForbidden();
});

it('searches by name or resident ID among the residents the viewer may see', function () {
    ($this->person)('Coralie', [], $this->other);

    $names = fn ($response) => collect($response->json('data'))->pluck('name')->all();

    expect($names($this->actingAs($this->admin)->getJson(route('reports.relationship.search', ['q' => 'Cora']))))->toBe(['Cora Cruz'])
        ->and($names($this->actingAs($this->stakeholder)->getJson(route('reports.relationship.search', ['q' => 'Cora']))))->toHaveCount(2)
        ->and($names($this->actingAs($this->admin)->getJson(route('reports.relationship.search', ['q' => $this->cora->system_id_no]))))->toBe(['Cora Cruz']);

    $this->actingAs($this->admin)->getJson(route('reports.relationship.search', ['q' => 'C']))->assertUnprocessable();
});

it('leaves archived residents out of the search', function () {
    ($this->person)('Gone', ['status' => ResidentStatus::Archived]);

    expect($this->actingAs($this->admin)->getJson(route('reports.relationship.search', ['q' => 'Gone']))->json('data'))->toBe([]);
});
