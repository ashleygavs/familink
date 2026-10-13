<?php

use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
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

    $this->actingAs($this->encoder)->get(route('residents.card', $foreign))->assertForbidden();
    $this->actingAs($this->admin)->get(route('residents.card', $foreign))->assertForbidden();
    $this->actingAs($this->encoder)->get(route('households.cards', $foreign->household))->assertForbidden();
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
