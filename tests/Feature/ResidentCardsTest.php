<?php

use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Municipality;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create();
    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create();
    $this->admin->managedBarangays()->sync([$this->barangay->id]);

    $this->make = fn (array $extra = []) => Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id, ...$extra,
    ]);
    $this->foreign = function () {
        $other = Barangay::factory()->create();

        return Resident::factory()->create(['barangay_id' => $other->id, 'household_id' => Household::factory()->create(['barangay_id' => $other->id])->id]);
    };
});

it('shows the QR card of an approved resident in scope', function () {
    $resident = ($this->make)();

    foreach ([$this->encoder, $this->admin] as $user) {
        $this->actingAs($user)->get(route('residents.card', $resident))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('residents/qr-cards')
                ->has('cards', 1)
                ->where('cards.0.qr_code_hash', $resident->qr_code_hash)
                ->where('cards.0.system_id_no', $resident->system_id_no)
                ->where('notice', null));
    }
});

it('gives no card to a resident who is not approved', function (ResidentStatus $status) {
    $resident = ($this->make)(['status' => $status]);

    $this->actingAs($this->encoder)->get(route('residents.card', $resident))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('cards', 0)->whereNot('notice', null));
})->with([ResidentStatus::Pending, ResidentStatus::Listed, ResidentStatus::Archived]);

it('prints every approved living member of a household but nobody else', function () {
    $approved = ($this->make)(['first_name' => 'Anna']);
    ($this->make)(['first_name' => 'Pending', 'status' => ResidentStatus::Pending]);
    ($this->make)(['first_name' => 'Late', 'is_deceased' => true]);
    ($this->make)(['first_name' => 'Gone', 'status' => ResidentStatus::Archived]);

    $this->actingAs($this->encoder)->get(route('households.cards', $this->household))
        ->assertInertia(fn ($page) => $page->has('cards', 1)->where('cards.0.id', $approved->id));

    $empty = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->actingAs($this->encoder)->get(route('households.cards', $empty))->assertInertia(fn ($page) => $page->has('cards', 0)->whereNot('notice', null));
});

it('keeps cards inside the viewer\'s own barangays', function () {
    $foreign = ($this->foreign)();

    $this->actingAs($this->encoder)->get(route('residents.card', $foreign))->assertNotFound();
    $this->actingAs($this->admin)->get(route('residents.card', $foreign))->assertNotFound();
    $this->actingAs($this->encoder)->get(route('households.cards', $foreign->household))->assertNotFound();
    $this->actingAs(User::factory()->withRole(RoleName::SuperAdmin)->create())->get(route('residents.card', $foreign))->assertOk();
});

it('keeps stakeholders and guests away from QR cards', function () {
    $resident = ($this->make)();

    $this->actingAs(User::factory()->withRole(RoleName::Stakeholder)->create())->get(route('residents.card', $resident))->assertForbidden();
    auth()->logout();
    $this->get(route('residents.card', $resident))->assertRedirect(route('login'));
});

it('lists approved residents of the viewer\'s area and searches by name, ID or household code', function () {
    $anna = ($this->make)(['first_name' => 'Anna', 'last_name' => 'Santos']);
    $ben = ($this->make)(['first_name' => 'Ben', 'last_name' => 'Reyes', 'household_id' => Household::factory()->create(['barangay_id' => $this->barangay->id, 'household_code' => 'HH-ZZ-0099'])->id]);
    ($this->make)(['first_name' => 'Waiting', 'status' => ResidentStatus::Pending]);
    ($this->foreign)();

    $names = function (array $query) {
        $names = [];
        $this->actingAs($this->encoder)->get(route('residents.index', $query))->assertOk()
            ->assertInertia(function ($page) use (&$names): void {
                $names = collect($page->toArray()['props']['residents']['data'])->pluck('full_name')->all();
            });

        return $names;
    };

    expect($names([]))->toHaveCount(2)
        ->and($names(['q' => 'santos']))->toBe([$anna->full_name])
        ->and($names(['q' => $ben->system_id_no]))->toBe([$ben->full_name])
        ->and($names(['q' => 'HH-ZZ-0099']))->toBe([$ben->full_name])
        ->and($names(['status' => 'Pending']))->toHaveCount(1)
        ->and($names(['status' => 'all']))->toHaveCount(3);

    $this->actingAs($this->encoder)->get(route('residents.index', ['status' => 'Archived']))->assertSessionHasErrors('status');
});

it('keeps the residents list to encoders and admins', function () {
    foreach ([RoleName::User, RoleName::Admin, RoleName::SuperAdmin] as $role) {
        $this->actingAs(User::factory()->withRole($role)->create())->get(route('residents.index'))->assertOk();
    }

    $this->actingAs(User::factory()->withRole(RoleName::Stakeholder)->create())->get(route('residents.index'))->assertForbidden();
});

it('lets a super admin narrow the residents list to one municipality or barangay', function () {
    $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create();
    $lubao = Municipality::factory()->create(['name' => 'Lubao']);
    $santaCruz = Barangay::factory()->create(['municipality_id' => $lubao->id, 'name' => 'Santa Cruz']);
    $sanNicolas = Barangay::factory()->create(['municipality_id' => $lubao->id, 'name' => 'San Nicolas']);
    $inHome = fn (Barangay $barangay, string $first) => Resident::factory()->create([
        'first_name' => $first, 'barangay_id' => $barangay->id, 'household_id' => Household::factory()->create(['barangay_id' => $barangay->id])->id,
    ]);
    ($this->make)(['first_name' => 'Zqbacolor']);
    $inHome($santaCruz, 'Zqcruz');
    $inHome($sanNicolas, 'Zqnicolas');

    $names = function (array $query) use ($superAdmin): array {
        $found = [];
        test()->actingAs($superAdmin)->get(route('residents.index', ['q' => 'Zq', ...$query]))->assertOk()
            ->assertInertia(function ($page) use (&$found): void {
                $found = collect($page->toArray()['props']['residents']['data'])->pluck('full_name')->map(fn ($name) => explode(' ', $name)[0])->sort()->values()->all();
            });

        return $found;
    };

    expect($names([]))->toBe(['Zqbacolor', 'Zqcruz', 'Zqnicolas'])
        ->and($names(['municipality' => $lubao->id]))->toBe(['Zqcruz', 'Zqnicolas'])
        ->and($names(['municipality' => $this->barangay->municipality_id]))->toBe(['Zqbacolor'])
        ->and($names(['barangay' => $santaCruz->id]))->toBe(['Zqcruz'])
        ->and($names(['municipality' => $lubao->id, 'barangay' => $sanNicolas->id]))->toBe(['Zqnicolas']);

    $this->actingAs($superAdmin)->get(route('residents.index', ['municipality' => $lubao->id, 'barangay' => $santaCruz->id]))->assertInertia(
        fn ($page) => $page->where('filters.municipality', $lubao->id)->where('filters.barangay', $santaCruz->id)->has('places', 2)
            ->where('residents.data.0.municipality_name', 'Lubao'),
    );
});

it('only offers an admin the places they handle and refuses a barangay outside them', function () {
    $other = Barangay::factory()->create();

    $this->actingAs($this->admin)->get(route('residents.index'))
        ->assertInertia(fn ($page) => $page->has('places', 1)->has('places.0.barangays', 1)->where('places.0.barangays.0.id', $this->barangay->id));

    $this->actingAs($this->admin)->get(route('residents.index', ['barangay' => $other->id]))->assertSessionHasErrors('barangay');
    $this->actingAs($this->admin)->get(route('residents.index', ['municipality' => $other->municipality_id]))->assertSessionHasErrors('municipality');
});
