<?php

use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentChange;
use App\Models\User;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['code' => 'SJ']);
    $this->admin = User::factory()->withRole(RoleName::Admin)->create();
    $this->admin->managedBarangays()->sync([$this->barangay->id]);

    $this->household = Household::factory()->create(['barangay_id' => $this->barangay->id]);
    $this->make = fn (array $extra = []) => Resident::factory()->create([
        'household_id' => $this->household->id, 'barangay_id' => $this->barangay->id,
        'middle_name' => null, 'birthdate' => '1990-05-05', ...$extra,
    ]);
    $this->couple = function (array $husband = [], array $wife = []) {
        $h = ($this->make)(['gender' => 'Male', 'civil_status' => 'Married', ...$husband]);
        $w = ($this->make)(['gender' => 'Female', 'civil_status' => 'Married', 'spouse_id' => $h->id, ...$wife]);
        $h->update(['spouse_id' => $w->id]);

        return [$h, $w];
    };
});

it('ends a marriage by separation and keeps a history for both people', function () {
    [$husband, $wife] = ($this->couple)();
    $child = ($this->make)(['father_id' => $husband->id, 'mother_id' => $wife->id, 'birthdate' => '2015-01-01']);

    $this->actingAs($this->admin)->post(route('admin.residents.marriage.end', $husband), ['civil_status' => 'Separated'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    expect($husband->fresh()->spouse_id)->toBeNull()
        ->and($wife->fresh()->spouse_id)->toBeNull()
        ->and($husband->fresh()->civil_status)->toBe('Separated')
        ->and($wife->fresh()->civil_status)->toBe('Separated')
        ->and($child->fresh()->father_id)->toBe($husband->id)
        ->and($child->fresh()->mother_id)->toBe($wife->id)
        ->and($husband->changes()->where('type', ResidentChange::MARRIAGE_ENDED)->first()->summary)->toContain('Separated')
        ->and($wife->changes()->where('type', ResidentChange::MARRIAGE_ENDED)->first()->changed_by_user_id)->toBe($this->admin->id);
});

it('lets someone go back to their maiden name and marry again after an annulment', function () {
    [$husband, $wife] = ($this->couple)(['last_name' => 'Cruz'], ['last_name' => 'Cruz', 'maiden_name' => 'Reyes']);

    $this->actingAs($this->admin)->post(route('admin.residents.marriage.end', $husband), [
        'civil_status' => 'Annulled', 'restore_maiden_name_ids' => [$wife->id],
    ])->assertSessionHasNoErrors();

    $wife->refresh();

    expect($wife->last_name)->toBe('Reyes')->and($wife->last_name_key)->toBe('reyes')->and($wife->maiden_name)->toBeNull()
        ->and($husband->fresh()->last_name)->toBe('Cruz')
        ->and($wife->changes()->first()->summary)->toContain('Went back to the surname Reyes (was Cruz)');

    $suitor = ($this->make)(['gender' => 'Male']);

    $this->actingAs($this->admin)->post(route('admin.residents.marriage', $wife), ['spouse_id' => $suitor->id])
        ->assertSessionHasNoErrors();

    expect($wife->fresh()->spouse_id)->toBe($suitor->id);
});

it('keeps the surname when nobody asks to restore a maiden name', function () {
    [$husband, $wife] = ($this->couple)([], ['last_name' => 'Cruz', 'maiden_name' => 'Reyes']);

    $this->actingAs($this->admin)->post(route('admin.residents.marriage.end', $wife), ['civil_status' => 'Separated'])->assertSessionHasNoErrors();

    expect($wife->fresh()->last_name)->toBe('Cruz')->and($wife->fresh()->maiden_name)->toBe('Reyes');
});

it('refuses marriages that cannot be ended', function (Closure $setup, string $message) {
    $resident = $setup($this);

    $this->actingAs($this->admin)->post(route('admin.residents.marriage.end', $resident), ['civil_status' => 'Separated'])
        ->assertSessionHasErrors('civil_status');

    expect(session('errors')->first('civil_status'))->toContain($message);
})->with([
    'no spouse' => [fn ($t) => ($t->make)(), 'no spouse on record'],
    'spouse has died' => [function ($t) {
        [$h, $w] = ($t->couple)();
        $w->update(['is_deceased' => true]);

        return $h;
    }, 'deceased'],
    'archived' => [function ($t) {
        [$h] = ($t->couple)();
        $h->update(['status' => ResidentStatus::Archived]);

        return $h;
    }, 'archived'],
    'spouse in another barangay' => [function ($t) {
        [$h, $w] = ($t->couple)();
        $w->update(['barangay_id' => Barangay::factory()->create()->id]);

        return $h;
    }, 'outside the barangays you handle'],
]);

it('validates the new status and the maiden name people', function () {
    [$husband, $wife] = ($this->couple)();
    $stranger = ($this->make)();

    $this->actingAs($this->admin)->post(route('admin.residents.marriage.end', $husband), ['civil_status' => 'Single'])
        ->assertSessionHasErrors('civil_status');
    $this->actingAs($this->admin)->post(route('admin.residents.marriage.end', $husband), ['civil_status' => 'Annulled', 'restore_maiden_name_ids' => [$stranger->id]])
        ->assertSessionHasErrors('restore_maiden_name_ids');

    expect($husband->fresh()->spouse_id)->toBe($wife->id);
});

it('keeps ending a marriage to admins of the resident\'s barangay', function () {
    [$husband] = ($this->couple)();
    $outsider = Resident::factory()->create(['barangay_id' => Barangay::factory()->create()->id]);

    $this->actingAs($this->admin)->post(route('admin.residents.marriage.end', $outsider), ['civil_status' => 'Separated'])->assertForbidden();

    foreach ([RoleName::User, RoleName::Stakeholder] as $role) {
        $this->actingAs(User::factory()->withRole($role)->create(['barangay_id' => $this->barangay->id]))
            ->post(route('admin.residents.marriage.end', $husband), ['civil_status' => 'Separated'])->assertForbidden();
    }
});

it('tells the page whether the marriage can be ended and who could restore a maiden name', function () {
    [$husband, $wife] = ($this->couple)([], ['last_name' => 'Cruz', 'maiden_name' => 'Reyes']);
    $single = ($this->make)();

    $this->actingAs($this->admin)->get(route('admin.residents.show', $husband))
        ->assertInertia(fn ($page) => $page->where('canEndMarriage', true)->has('maidenNameChoices', 1)->where('maidenNameChoices.0.id', $wife->id));
    $this->actingAs($this->admin)->get(route('admin.residents.show', $single))
        ->assertInertia(fn ($page) => $page->where('canEndMarriage', false)->has('maidenNameChoices', 0));
});
