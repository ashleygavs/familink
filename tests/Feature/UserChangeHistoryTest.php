<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Role;
use App\Models\User;
use App\Models\UserChange;

beforeEach(function () {
    foreach (RoleName::cases() as $role) {
        Role::query()->firstOrCreate(['name' => $role->value]);
    }

    $this->municipality = Municipality::factory()->create(['name' => 'Bacolor']);
    $this->sanJose = Barangay::factory()->create(['municipality_id' => $this->municipality->id, 'name' => 'San Jose']);
    $this->cabalantian = Barangay::factory()->create(['municipality_id' => $this->municipality->id, 'name' => 'Cabalantian']);
    $this->super = User::factory()->withRole(RoleName::SuperAdmin)->create(['name' => 'Chief']);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create(['municipality_id' => $this->municipality->id, 'name' => 'Local Admin']);
    $this->admin->managedBarangays()->sync([$this->sanJose->id]);

    $this->encoderPayload = fn (array $extra = []) => [
        'name' => '', 'email' => 'new.encoder@example.com', 'password' => 'a-long-safe-password',
        'role' => 'user', 'barangay_id' => $this->sanJose->id, ...$extra,
    ];
    $this->encoder = fn (array $extra = []) => User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->sanJose->id, ...$extra]);
});

it('records who created an account and where it works', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), ($this->encoderPayload)())->assertSessionHasNoErrors();

    $change = UserChange::query()->sole();

    expect($change->type)->toBe(UserChange::CREATED)
        ->and($change->changed_by_user_id)->toBe($this->admin->id)
        ->and($change->target_email)->toBe('new.encoder@example.com')
        ->and($change->barangay_id)->toBe($this->sanJose->id)
        ->and($change->summary)->toContain('Encoder')->toContain('San Jose')->toContain('new.encoder@example.com');
});

it('never writes a password into the history', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), ($this->encoderPayload)())->assertSessionHasNoErrors();

    expect(json_encode(UserChange::query()->get()->toArray()))->not->toContain('a-long-safe-password');
});

it('records each change made to an account with the old and new value', function () {
    $encoder = ($this->encoder)(['name' => 'BAC_San Jose Encoder', 'email' => 'old@example.com']);

    $this->actingAs($this->super)->put(route('admin.users.update', $encoder), [
        'name' => '', 'email' => 'old@example.com', 'password' => '', 'role' => 'user', 'barangay_id' => $this->cabalantian->id,
    ])->assertSessionHasNoErrors();

    $change = UserChange::query()->sole();

    expect($change->type)->toBe(UserChange::UPDATED)
        ->and($change->summary)->toBe('Changed Name from BAC_San Jose Encoder to BAC_Cabalantian Encoder; Barangay from San Jose to Cabalantian.')
        ->and($change->data['before']['barangay'])->toBe('San Jose')
        ->and($change->data['after']['barangay'])->toBe('Cabalantian')
        ->and($change->data['password_changed'])->toBeFalse();
});

it('says a password was set without saying what it is', function () {
    $encoder = ($this->encoder)(['name' => 'BAC_San Jose Encoder']);

    $this->actingAs($this->admin)->put(route('admin.users.update', $encoder), [
        'name' => '', 'email' => $encoder->email, 'password' => 'another-long-safe-password', 'role' => 'user', 'barangay_id' => $this->sanJose->id,
    ])->assertSessionHasNoErrors();

    $change = UserChange::query()->sole();

    expect($change->summary)->toBe('A new password was set.')->and(json_encode($change->toArray()))->not->toContain('another-long-safe-password');
});

it('records nothing when a save changes nothing', function () {
    $encoder = ($this->encoder)(['name' => 'BAC_San Jose Encoder']);

    $this->actingAs($this->admin)->put(route('admin.users.update', $encoder), [
        'name' => '', 'email' => $encoder->email, 'password' => '', 'role' => 'user', 'barangay_id' => $this->sanJose->id,
    ])->assertSessionHasNoErrors();

    expect(UserChange::query()->count())->toBe(0);
});

it('records deactivating and reactivating an account', function () {
    $encoder = ($this->encoder)();

    $this->actingAs($this->admin)->post(route('admin.users.deactivate', $encoder))->assertRedirect();
    $this->actingAs($this->admin)->post(route('admin.users.reactivate', $encoder))->assertRedirect();

    expect(UserChange::query()->orderBy('id')->pluck('type')->all())->toBe([UserChange::DEACTIVATED, UserChange::REACTIVATED]);
});

it('keeps the history of an account that is deleted', function () {
    $encoder = ($this->encoder)(['name' => 'Mistake Account', 'email' => 'mistake@example.com']);

    $this->actingAs($this->super)->delete(route('admin.users.destroy', $encoder))->assertRedirect();

    $change = UserChange::query()->sole();

    expect(User::query()->find($encoder->id))->toBeNull()
        ->and($change->type)->toBe(UserChange::DELETED)
        ->and($change->user_id)->toBeNull()
        ->and($change->target_name)->toBe('Mistake Account')
        ->and($change->target_email)->toBe('mistake@example.com');
});

it('stops an account that made changes from being deleted, so the history keeps its author', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), ($this->encoderPayload)())->assertSessionHasNoErrors();

    expect($this->admin->hasHistory())->toBeTrue();
});

it('shows an admin only the changes of the encoders they handle, and a super admin all of them', function () {
    UserChange::factory()->create(['barangay_id' => $this->sanJose->id, 'summary' => 'Visible change.', 'changed_by_user_id' => $this->super->id]);
    UserChange::factory()->create(['barangay_id' => $this->cabalantian->id, 'summary' => 'Hidden change.', 'changed_by_user_id' => $this->super->id]);
    UserChange::factory()->create(['barangay_id' => null, 'summary' => 'Staff change.', 'changed_by_user_id' => $this->super->id]);

    $this->actingAs($this->admin)->get(route('admin.users'))
        ->assertInertia(fn ($page) => $page->has('history', 1)->where('history.0.summary', 'Visible change.'));

    $this->actingAs($this->super)->get(route('admin.users'))
        ->assertInertia(fn ($page) => $page->has('history', 3));
});

it('lists the newest changes first and no more than thirty', function () {
    UserChange::factory()->count(35)->create(['barangay_id' => $this->sanJose->id, 'changed_by_user_id' => $this->super->id]);
    $newest = UserChange::factory()->create(['barangay_id' => $this->sanJose->id, 'summary' => 'Newest.', 'changed_by_user_id' => $this->super->id]);

    $this->actingAs($this->admin)->get(route('admin.users'))
        ->assertInertia(fn ($page) => $page->has('history', 30)->where('history.0.id', $newest->id));
});
