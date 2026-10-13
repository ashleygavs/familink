<?php

use App\Enums\RelativeKind;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\RelativeLink;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['code' => 'SJ']);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);
    $this->marcus = Resident::factory()->create(['barangay_id' => $this->barangay->id, 'first_name' => 'Marcus', 'birthdate' => '2004-01-21']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function declaredPayload(array $overrides = []): array
{
    return [
        'path' => 'existing',
        'first_name' => 'Rica',
        'last_name' => 'Torres',
        'birthdate' => '2001-03-03',
        'gender' => 'Female',
        'civil_status' => 'Single',
        'household_mode' => 'new',
        'street_address' => '5 Mabini Street',
        'household_role' => 'Other',
        ...$overrides,
    ];
}

it('saves the relatives a person named when registering', function () {
    $uncle = Resident::factory()->create(['barangay_id' => $this->barangay->id]);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), declaredPayload([
        'via_relatives' => ['cousin' => $this->marcus->id, 'uncle_aunt' => $uncle->id],
    ]))->assertSessionHasNoErrors();

    $rica = Resident::query()->where('first_name', 'Rica')->firstOrFail();

    expect(RelativeLink::query()->where('resident_id', $rica->id)->count())->toBe(2)
        ->and(RelativeLink::query()->where('resident_id', $rica->id)->where('relative_id', $this->marcus->id)->firstOrFail()->relation)->toBe(RelativeKind::Cousin)
        ->and(RelativeLink::query()->where('resident_id', $rica->id)->where('relative_id', $uncle->id)->firstOrFail()->relation)->toBe(RelativeKind::UncleAunt);
});

it('ignores unknown kinds and empty entries', function () {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), declaredPayload([
        'via_relatives' => ['cousin' => $this->marcus->id, 'best_friend' => $this->marcus->id, 'sibling' => ''],
    ]))->assertSessionHasNoErrors();

    expect(RelativeLink::query()->count())->toBe(1)
        ->and(RelativeLink::query()->firstOrFail()->relation)->toBe(RelativeKind::Cousin);
});

it('rejects an archived or missing resident as a named relative', function () {
    $archived = Resident::factory()->create(['status' => 'Archived']);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), declaredPayload(['via_relatives' => ['cousin' => $archived->id]]))
        ->assertSessionHasErrors('via_relatives.cousin.0');
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), declaredPayload(['via_relatives' => ['cousin' => 999999]]))
        ->assertSessionHasErrors('via_relatives.cousin.0');

    expect(Resident::query()->where('first_name', 'Rica')->exists())->toBeFalse();
});

it('does not take named relatives from someone who starts a new family record', function () {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), declaredPayload([
        'path' => 'new',
        'via_relatives' => ['cousin' => $this->marcus->id],
        'consent' => true,
        'relatives' => null,
    ]))->assertSessionHasNoErrors();

    expect(RelativeLink::query()->count())->toBe(0);
});

it('shows admins the relatives a pending resident named, from both sides', function () {
    $admin = User::factory()->withRole(RoleName::Admin)->create();
    $this->marcus->update(['status' => 'Pending']);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), declaredPayload(['via_relatives' => ['grandparent' => $this->marcus->id]]));

    $this->actingAs($admin)->get(route('admin.approvals'))
        ->assertInertia(function ($page) {
            $rows = collect($page->toArray()['props']['pending']['data'])->keyBy('full_name');

            expect($rows['Rica Torres']['declared_relatives'][0])->toMatchArray(['relation' => 'Grandfather or grandmother', 'name' => $this->marcus->full_name])
                ->and($rows[$this->marcus->full_name]['declared_relatives'][0])->toMatchArray(['relation' => 'Grandchild', 'name' => 'Rica Torres']);
        });
});

it('knows the inverse of every kind of relative', function () {
    expect(RelativeKind::Grandparent->inverse())->toBe(RelativeKind::Grandchild)
        ->and(RelativeKind::UncleAunt->inverse())->toBe(RelativeKind::NephewNiece)
        ->and(RelativeKind::Cousin->inverse())->toBe(RelativeKind::Cousin)
        ->and(RelativeKind::Sibling->inverse()->inverse())->toBe(RelativeKind::Sibling);
});

it('saves several relatives of the same kind', function () {
    $second = Resident::factory()->create(['barangay_id' => $this->barangay->id, 'first_name' => 'McKenzie', 'birthdate' => '2010-10-31']);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), declaredPayload([
        'via_relatives' => ['cousin' => [$this->marcus->id, $second->id, $second->id]],
    ]))->assertSessionHasNoErrors();

    $rica = Resident::query()->where('first_name', 'Rica')->firstOrFail();

    expect(RelativeLink::query()->where('resident_id', $rica->id)->pluck('relative_id')->sort()->values()->all())
        ->toBe([$this->marcus->id, $second->id]);
});

it('rejects an archived resident inside a list of relatives', function () {
    $archived = Resident::factory()->create(['status' => 'Archived']);

    $this->actingAs($this->encoder)->post(route('encoder.register.store'), declaredPayload([
        'via_relatives' => ['cousin' => [$this->marcus->id, $archived->id]],
    ]))->assertSessionHasErrors('via_relatives.cousin.1');
});
