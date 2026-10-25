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
        ->assertInertia(fn ($page) => $page->has('history.data', 1)->where('history.data.0.summary', 'Visible change.'));

    $this->actingAs($this->super)->get(route('admin.users'))
        ->assertInertia(fn ($page) => $page->has('history.data', 3));
});

it('lists the newest changes first, fifteen to a page, so older ones can still be reached', function () {
    UserChange::factory()->count(20)->create(['barangay_id' => $this->sanJose->id, 'changed_by_user_id' => $this->super->id]);
    $oldest = UserChange::query()->orderBy('id')->first();
    $newest = UserChange::factory()->create(['barangay_id' => $this->sanJose->id, 'summary' => 'Newest.', 'changed_by_user_id' => $this->super->id]);

    $this->actingAs($this->admin)->get(route('admin.users'))
        ->assertInertia(fn ($page) => $page
            ->has('history.data', 15)
            ->where('history.data.0.id', $newest->id)
            ->where('history.total', 21)
            ->where('history.last_page', 2));

    $this->actingAs($this->admin)->get(route('admin.users', ['history_page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->has('history.data', 6)
            ->where('history.data.5.id', $oldest->id));
});

it('narrows the history to one kind of change', function () {
    UserChange::factory()->create(['barangay_id' => $this->sanJose->id, 'type' => UserChange::CREATED, 'changed_by_user_id' => $this->super->id]);
    UserChange::factory()->count(2)->create(['barangay_id' => $this->sanJose->id, 'type' => UserChange::DEACTIVATED, 'changed_by_user_id' => $this->super->id]);

    $this->actingAs($this->admin)->get(route('admin.users', ['history_type' => 'deactivated']))
        ->assertInertia(fn ($page) => $page->has('history.data', 2)->where('historyFilters.type', 'deactivated'));

    $this->actingAs($this->admin)->get(route('admin.users', ['history_type' => 'not-a-type']))->assertSessionHasErrors('history_type');
});

it('shows the history of one account only, with its name', function () {
    $mine = ($this->encoder)(['name' => 'BAC_San Jose Encoder']);
    $other = ($this->encoder)(['name' => 'Someone Else']);
    UserChange::factory()->count(2)->create(['user_id' => $mine->id, 'barangay_id' => $this->sanJose->id, 'changed_by_user_id' => $this->super->id]);
    UserChange::factory()->create(['user_id' => $other->id, 'barangay_id' => $this->sanJose->id, 'changed_by_user_id' => $this->super->id]);

    $this->actingAs($this->admin)->get(route('admin.users', ['history_user' => $mine->id]))
        ->assertInertia(fn ($page) => $page
            ->has('history.data', 2)
            ->where('historyFilters.user', $mine->id)
            ->where('historyFilters.user_name', 'BAC_San Jose Encoder'));
});

it('does not reveal an account outside the admin\'s barangays through the account filter', function () {
    $far = ($this->encoder)(['barangay_id' => $this->cabalantian->id, 'name' => 'Far Away Encoder']);
    UserChange::factory()->create(['user_id' => $far->id, 'barangay_id' => $this->cabalantian->id, 'changed_by_user_id' => $this->super->id]);

    $this->actingAs($this->admin)->get(route('admin.users', ['history_user' => $far->id]))
        ->assertInertia(fn ($page) => $page->has('history.data', 0)->where('historyFilters.user_name', null));
});

it('searches the history by account, email, summary or who made the change', function () {
    $make = fn (array $extra) => UserChange::factory()->create(['barangay_id' => $this->sanJose->id, 'changed_by_user_id' => $this->super->id, ...$extra]);
    $make(['target_name' => 'Zelda Encoder', 'target_email' => 'zelda@example.com', 'summary' => 'Created the account.']);
    $make(['target_name' => 'Other Person', 'target_email' => 'other@example.com', 'summary' => 'Changed Barangay from Alpha to Omega.']);
    $make(['target_name' => 'Third Person', 'target_email' => 'third@example.com', 'summary' => 'Deactivated.', 'changed_by_user_id' => $this->admin->id]);

    $count = fn (string $term) => $this->actingAs($this->admin)->get(route('admin.users', ['history_q' => $term]))->viewData('page')['props']['history']['total'];

    expect($count('Zelda'))->toBe(1)
        ->and($count('third@example'))->toBe(1)
        ->and($count('Omega'))->toBe(1)
        ->and($count('Local Admin'))->toBe(1)
        ->and($count('nothing matches this'))->toBe(0);
});

it('treats percent and underscore in a search as plain characters', function () {
    UserChange::factory()->create(['barangay_id' => $this->sanJose->id, 'summary' => 'Changed role.', 'changed_by_user_id' => $this->super->id]);

    $response = $this->actingAs($this->admin)->get(route('admin.users', ['history_q' => '%']));

    expect($response->viewData('page')['props']['history']['total'])->toBe(0);
});

it('keeps the filters in the links to the next pages', function () {
    UserChange::factory()->count(20)->create(['barangay_id' => $this->sanJose->id, 'type' => UserChange::UPDATED, 'changed_by_user_id' => $this->super->id]);

    $this->actingAs($this->admin)->get(route('admin.users', ['history_type' => 'updated', 'history_q' => 'Changed']))
        ->assertInertia(fn ($page) => $page->where('history.next_page_url', fn (string $url) => str_contains($url, 'history_type=updated') && str_contains($url, 'history_q=Changed') && str_contains($url, 'history_page=2')));
});

it('offers every kind of change as a filter', function () {
    $this->actingAs($this->admin)->get(route('admin.users'))
        ->assertInertia(fn ($page) => $page->where('historyTypes', UserChange::TYPES));
});
