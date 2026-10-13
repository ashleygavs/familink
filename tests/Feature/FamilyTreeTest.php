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
