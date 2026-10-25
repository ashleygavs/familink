<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Municipality;
use App\Models\ReliefEvent;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->municipality = Municipality::factory()->create();
    $this->mine = Barangay::factory()->create(['municipality_id' => $this->municipality->id]);
    $this->other = Barangay::factory()->create(['municipality_id' => $this->municipality->id]);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $this->municipality->id]);
    $this->admin->managedBarangays()->sync([$this->mine->id]);

    $this->foreignHousehold = Household::factory()->create(['barangay_id' => $this->other->id]);
    $this->foreignResident = Resident::factory()->create(['barangay_id' => $this->other->id, 'household_id' => $this->foreignHousehold->id]);
    $this->foreignEvent = ReliefEvent::factory()->draft()->create(['municipality_id' => Municipality::factory()->create()->id]);
    $this->foreignStaff = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->other->id]);
});

it('answers the same for a record in another area as for one that does not exist', function (string $method, string $route, string $parameter) {
    $real = match ($parameter) {
        'resident' => $this->foreignResident,
        'household' => $this->foreignHousehold,
        'event' => $this->foreignEvent,
        'user' => $this->foreignStaff,
    };
    $missing = 999999;

    $outside = $this->actingAs($this->admin)->call($method, route($route, [$parameter => $real->id]));
    $nothing = $this->actingAs($this->admin)->call($method, route($route, [$parameter => $missing]));

    expect($outside->status())->toBe(404)->and($nothing->status())->toBe(404);
})->with([
    'resident page' => ['GET', 'admin.residents.show', 'resident'],
    'resident card' => ['GET', 'residents.card', 'resident'],
    'mark deceased' => ['POST', 'admin.residents.deceased', 'resident'],
    'approve' => ['POST', 'admin.approvals.approve', 'resident'],
    'archive' => ['POST', 'admin.approvals.archive', 'resident'],
    'household cards' => ['GET', 'households.cards', 'household'],
    'family tree' => ['GET', 'family-tree', 'household'],
    'edit event' => ['PUT', 'admin.events.update', 'event'],
    'delete event' => ['DELETE', 'admin.events.destroy', 'event'],
    'claims export' => ['GET', 'reports.claims-csv', 'event'],
    'deactivate staff' => ['POST', 'admin.users.deactivate', 'user'],
]);

it('still says forbidden when a role may never open the page at all', function () {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id]);

    $this->actingAs($encoder)->get(route('admin.users'))->assertForbidden();
    $this->actingAs($encoder)->post(route('admin.approvals.approve', $this->foreignResident))->assertForbidden();
});

it('spends about the same time answering an email with no account as one with an account', function () {
    // The test setup uses a very cheap hash, so give it the real cost for this check.
    Hash::driver('bcrypt')->setRounds(12);
    $user = User::factory()->create(['password' => Hash::make('the-right-password')]);

    $time = function (string $email): float {
        $start = microtime(true);
        $this->post(route('login.store'), ['email' => $email, 'password' => 'the-wrong-password']);

        return microtime(true) - $start;
    };

    $time($user->email);
    $known = $time($user->email);
    $unknown = $time('nobody@example.com');

    expect($unknown)->toBeGreaterThan($known * 0.5);
});

it('still rejects an unknown email and a wrong password with the same message', function () {
    $user = User::factory()->create();

    $unknown = $this->from(route('login'))->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'whatever-it-is']);
    $wrong = $this->from(route('login'))->post(route('login.store'), ['email' => $user->email, 'password' => 'whatever-it-is']);

    $message = 'The email or password is not correct. Please check and try again.';
    $unknown->assertSessionHasErrors(['email' => $message]);
    $wrong->assertSessionHasErrors(['email' => $message]);
});

it('answers a role that may never open the page the same for a real and an invented record', function (string $method, string $route, string $parameter) {
    $encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->mine->id]);
    $real = match ($parameter) {
        'resident' => $this->foreignResident,
        'user' => $this->foreignStaff,
        'event' => $this->foreignEvent,
    };

    $withReal = $this->actingAs($encoder)->call($method, route($route, [$parameter => $real->id]));
    $withInvented = $this->actingAs($encoder)->call($method, route($route, [$parameter => 999999]));

    expect($withReal->status())->toBe(403)->and($withInvented->status())->toBe(403);
})->with([
    'resident page' => ['GET', 'admin.residents.show', 'resident'],
    'approve' => ['POST', 'admin.approvals.approve', 'resident'],
    'edit staff' => ['PUT', 'admin.users.update', 'user'],
    'edit event' => ['PUT', 'admin.events.update', 'event'],
    'delete staff' => ['DELETE', 'admin.users.destroy', 'user'],
]);
