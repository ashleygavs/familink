<?php

use App\Actions\Relief\BuildFamilyTree;
use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $this->household = Household::factory()->create();
    $this->make = fn (string $first, string $gender, array $extra = []) => Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->household->barangay_id,
        'first_name' => $first, 'gender' => $gender, ...$extra,
    ]);
});

/**
 * @return array<string, mixed>
 */
function treeFor(Household $household): array
{
    return app(BuildFamilyTree::class)->handle($household);
}

it('includes the household, its parents and children, and the spouse across generations', function () {
    $elsewhere = Household::factory()->create();
    $grandfather = Resident::factory()->create(['household_id' => $elsewhere->id, 'first_name' => 'Grandpa', 'gender' => 'Male']);
    $father = ($this->make)('Father', 'Male', ['father_id' => $grandfather->id]);
    $mother = ($this->make)('Mother', 'Female', ['spouse_id' => $father->id]);
    $father->update(['spouse_id' => $mother->id]);
    $child = ($this->make)('Child', 'Female', ['father_id' => $father->id, 'mother_id' => $mother->id]);
    $grandchild = Resident::factory()->create(['household_id' => $elsewhere->id, 'first_name' => 'Grandchild', 'mother_id' => $child->id]);

    $tree = treeFor($this->household);
    $names = collect($tree['nodes'])->pluck('name', 'id');
    $edges = collect($tree['edges']);

    expect($names->keys()->all())->toContain($grandfather->id, $father->id, $mother->id, $child->id, $grandchild->id)
        ->and($edges->contains(fn ($edge) => $edge['kind'] === 'parent' && $edge['from'] === $grandfather->id && $edge['to'] === $father->id))->toBeTrue()
        ->and($edges->contains(fn ($edge) => $edge['kind'] === 'parent' && $edge['from'] === $mother->id && $edge['to'] === $child->id))->toBeTrue()
        ->and($edges->where('kind', 'spouse'))->toHaveCount(1)
        ->and(collect($tree['nodes'])->firstWhere('id', $grandfather->id)['in_household'])->toBeFalse()
        ->and(collect($tree['nodes'])->firstWhere('id', $father->id)['in_household'])->toBeTrue()
        ->and($tree['truncated'])->toBeFalse();
});

it('leaves out archived residents and stops at the size cap', function () {
    $archived = ($this->make)('Gone', 'Male', ['status' => ResidentStatus::Archived]);
    $parent = ($this->make)('Parent', 'Female');

    foreach (range(1, 130) as $number) {
        Resident::factory()->create(['household_id' => Household::factory()->create()->id, 'mother_id' => $parent->id]);
    }

    $tree = treeFor($this->household);

    expect(collect($tree['nodes'])->pluck('id'))->not->toContain($archived->id)
        ->and(count($tree['nodes']))->toBeLessThanOrEqual(120)
        ->and($tree['truncated'])->toBeTrue();
});

it('hands the tree and household details to the page', function () {
    $head = ($this->make)('Head', 'Male');
    $this->household->update(['head_resident_id' => $head->id]);
    $stakeholder = User::factory()->withRole(RoleName::Stakeholder)->create();

    $this->actingAs($stakeholder)->get(route('family-tree', $this->household))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/family-tree')
            ->where('household.household_code', $this->household->household_code)
            ->where('household.head_name', $head->full_name)
            ->has('tree.nodes', 1));
});

/**
 * Four generations of one family, with the household's head at the bottom of the main line.
 *
 * @return array<string, Resident>
 */
function clan(): array
{
    $elsewhere = fn (string $first, string $gender, array $extra = []) => Resident::factory()->create([
        'household_id' => Household::factory()->create()->id, 'first_name' => $first, 'gender' => $gender, ...$extra,
    ]);

    $greatGrandfather = $elsewhere('Great-grandpa', 'Male');
    $grandfather = $elsewhere('Grandpa', 'Male', ['father_id' => $greatGrandfather->id]);
    $grandfatherBrother = $elsewhere('Granduncle', 'Male', ['father_id' => $greatGrandfather->id]);
    $father = $elsewhere('Father', 'Male', ['father_id' => $grandfather->id]);
    $aunt = $elsewhere('Aunt', 'Female', ['father_id' => $grandfather->id]);
    $cousin = $elsewhere('Cousin', 'Female', ['mother_id' => $aunt->id]);
    $cousinSpouse = $elsewhere('Cousin spouse', 'Male', ['spouse_id' => $cousin->id]);
    $cousinInLaw = $elsewhere('Cousin in-law', 'Male');
    $cousinSpouse->update(['father_id' => $cousinInLaw->id]);
    $cousin->update(['spouse_id' => $cousinSpouse->id]);
    $distantParent = $elsewhere('Distant parent', 'Female', ['father_id' => $grandfatherBrother->id]);
    $secondCousin = $elsewhere('Second cousin', 'Male', ['mother_id' => $distantParent->id]);

    return get_defined_vars();
}

it('adds first cousins at the cousins level and second cousins at the wide level', function () {
    $clan = clan();
    $me = ($this->make)('Me', 'Male', ['father_id' => $clan['father']->id]);
    $this->household->update(['head_resident_id' => $me->id]);

    $ids = fn (string $reach) => collect(app(BuildFamilyTree::class)->handle($this->household, $reach)['nodes'])->pluck('id');

    expect($ids('close'))->toContain($clan['aunt']->id, $clan['greatGrandfather']->id)
        ->and($ids('close'))->not->toContain($clan['cousin']->id, $clan['secondCousin']->id)
        ->and($ids('cousins'))->toContain($clan['cousin']->id)
        ->and($ids('cousins'))->not->toContain($clan['secondCousin']->id)
        ->and($ids('wide'))->toContain($clan['cousin']->id, $clan['secondCousin']->id, $clan['distantParent']->id);
});

it('labels each person by how they are related to the head of the household', function () {
    $clan = clan();
    $me = ($this->make)('Me', 'Male', ['father_id' => $clan['father']->id]);
    $this->household->update(['head_resident_id' => $me->id]);

    $tree = app(BuildFamilyTree::class)->handle($this->household, 'wide');
    $relation = collect($tree['nodes'])->pluck('relation', 'id');

    expect($tree['reference'])->toBe(['id' => $me->id, 'name' => $me->full_name])
        ->and($tree['reach'])->toBe('wide')
        ->and($relation[$me->id])->toBeNull()
        ->and($relation[$clan['father']->id])->toBe('Father')
        ->and($relation[$clan['grandfather']->id])->toBe('Grandfather')
        ->and($relation[$clan['aunt']->id])->toBe('Aunt')
        ->and($relation[$clan['cousin']->id])->toBe('First cousin')
        ->and($relation[$clan['secondCousin']->id])->toBe('Second cousin')
        ->and($relation[$clan['cousinSpouse']->id])->toBe('Husband of first cousin');
});

it('gives the labels from the first member when the household has no head', function () {
    $clan = clan();
    $first = ($this->make)('First', 'Male', ['father_id' => $clan['father']->id]);

    expect(app(BuildFamilyTree::class)->handle($this->household)['reference']['id'])->toBe($first->id);
});

it('follows only blood lines past the close family and shows a spouse who married in without their family', function () {
    $clan = clan();
    ($this->make)('Me', 'Male', ['father_id' => $clan['father']->id]);
    $ids = fn (string $reach) => collect(app(BuildFamilyTree::class)->handle($this->household, $reach)['nodes'])->pluck('id');

    expect($ids('wide'))->toContain($clan['cousinSpouse']->id)
        ->and($ids('wide'))->not->toContain($clan['cousinInLaw']->id)
        ->and($ids('cousins'))->not->toContain($clan['cousinSpouse']->id);
});

it('still follows a spouse\'s parents within the close family, as before', function () {
    $inLaw = Resident::factory()->create(['household_id' => Household::factory()->create()->id, 'first_name' => 'Mother-in-law', 'gender' => 'Female']);
    $spouse = Resident::factory()->create(['household_id' => Household::factory()->create()->id, 'first_name' => 'Spouse', 'mother_id' => $inLaw->id]);
    $me = ($this->make)('Me', 'Male', ['spouse_id' => $spouse->id]);
    $spouse->update(['spouse_id' => $me->id]);

    $tree = app(BuildFamilyTree::class)->handle($this->household);

    expect(collect($tree['nodes'])->pluck('id'))->toContain($spouse->id, $inLaw->id)
        ->and(collect($tree['nodes'])->firstWhere('id', $inLaw->id)['relation'])->toBe("Spouse's mother");
});

it('allows a bigger tree at the wider levels', function () {
    $parent = ($this->make)('Parent', 'Female');

    foreach (range(1, 130) as $number) {
        Resident::factory()->create(['household_id' => Household::factory()->create()->id, 'mother_id' => $parent->id]);
    }

    $close = app(BuildFamilyTree::class)->handle($this->household);
    $wide = app(BuildFamilyTree::class)->handle($this->household, 'wide');

    expect(count($close['nodes']))->toBeLessThanOrEqual(120)->and($close['truncated'])->toBeTrue()
        ->and(count($wide['nodes']))->toBeGreaterThan(120)->and($wide['truncated'])->toBeFalse();
});

it('passes the chosen reach to the page and shows the close family for an unknown one', function () {
    $stakeholder = User::factory()->withRole(RoleName::Stakeholder)->create();
    ($this->make)('Head', 'Male');

    $this->actingAs($stakeholder)->get(route('family-tree', $this->household))
        ->assertInertia(fn ($page) => $page->where('tree.reach', 'close'));
    $this->actingAs($stakeholder)->get(route('family-tree', ['household' => $this->household, 'reach' => 'wide']))
        ->assertInertia(fn ($page) => $page->where('tree.reach', 'wide')->has('tree.reference'));
    $this->actingAs($stakeholder)->get(route('family-tree', ['household' => $this->household, 'reach' => 'everyone']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('tree.reach', 'close'));
});

it('puts each relative in a group, ordered by how far away they are', function () {
    $clan = clan();
    $me = ($this->make)('Me', 'Male', ['father_id' => $clan['father']->id]);
    $this->household->update(['head_resident_id' => $me->id]);

    $tree = app(BuildFamilyTree::class)->handle($this->household, 'wide');
    $node = fn (Resident $resident) => collect($tree['nodes'])->firstWhere('id', $resident->id);

    expect($node($clan['father']))->toMatchArray(['relation' => 'Father', 'relation_group' => 'Parents', 'relation_rank' => 1])
        ->and($node($clan['grandfather']))->toMatchArray(['relation_group' => 'Grandparents', 'relation_rank' => 2])
        ->and($node($clan['aunt']))->toMatchArray(['relation_group' => 'Aunts and uncles', 'relation_rank' => 3])
        ->and($node($clan['cousin']))->toMatchArray(['relation_group' => 'First cousins', 'relation_rank' => 4])
        ->and($node($clan['secondCousin']))->toMatchArray(['relation_group' => 'Second cousins', 'relation_rank' => 6])
        ->and($node($clan['cousinSpouse']))->toMatchArray(['relation_group' => 'Related by marriage'])
        ->and($node($me))->toMatchArray(['relation' => null, 'relation_group' => null, 'relation_rank' => null]);
});

it('says when relatives were cut off, and not when only spouses of distant relatives were', function () {
    $elsewhere = Household::factory()->create();
    $person = fn (string $first, array $extra = []) => Resident::factory()->create(['household_id' => $elsewhere->id, 'first_name' => $first, ...$extra]);

    // Too many children: relatives themselves are cut.
    $parent = ($this->make)('Parent', 'Female');
    foreach (range(1, 130) as $number) {
        $person('Child'.$number, ['mother_id' => $parent->id]);
    }
    $crowded = app(BuildFamilyTree::class)->handle($this->household);

    expect($crowded['truncated'])->toBeTrue()->and($crowded['truncated_relatives'])->toBeTrue();
});

it('only cuts spouses when the family fits but their partners do not', function () {
    $elsewhere = Household::factory()->create();
    $person = fn (string $first, array $extra = []) => Resident::factory()->create(['household_id' => $elsewhere->id, 'first_name' => $first, ...$extra]);

    $grandfather = $person('Grandpa');
    $father = $person('Father', ['father_id' => $grandfather->id]);
    ($this->make)('Me', 'Male', ['father_id' => $father->id]);

    // 100 aunts each have a child, a first cousin. The cousins' husbands and wives are only shown, one step further out.
    foreach (range(1, 100) as $number) {
        $aunt = $person('Aunt'.$number, ['father_id' => $grandfather->id]);
        $cousin = $person('Cousin'.$number, ['mother_id' => $aunt->id]);
        $spouse = $person('Spouse'.$number, ['spouse_id' => $cousin->id]);
        $cousin->update(['spouse_id' => $spouse->id]);
    }

    $tree = app(BuildFamilyTree::class)->handle($this->household, 'wide');

    expect($tree['truncated'])->toBeTrue()->and($tree['truncated_relatives'])->toBeFalse()
        ->and(collect($tree['nodes'])->where('relation_group', 'First cousins'))->toHaveCount(100);
});
