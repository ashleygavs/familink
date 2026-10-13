<?php

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;

beforeEach(function () {
    $this->barangay = Barangay::factory()->create(['code' => 'SJ']);
    $this->encoder = User::factory()->withRole(RoleName::User)->create(['barangay_id' => $this->barangay->id]);
});

it('capitalises plain names but keeps names typed with their own capitals', function (string $first, string $last, string $expectedFirst, string $expectedLast) {
    $this->actingAs($this->encoder)->post(route('encoder.register.store'), [
        'path' => 'existing',
        'first_name' => $first,
        'last_name' => $last,
        'birthdate' => '2010-10-31',
        'gender' => 'Male',
        'civil_status' => 'Single',
        'household_mode' => 'new',
        'street_address' => '1000 T. Bugallion',
        'household_role' => 'Other',
    ])->assertSessionHasNoErrors();

    $resident = Resident::query()->firstOrFail();

    expect($resident->first_name)->toBe($expectedFirst)->and($resident->last_name)->toBe($expectedLast);
})->with([
    'camel case is kept' => ['McKenzie', 'Cariño', 'McKenzie', 'Cariño'],
    'upper case ñ is lowered properly' => ['MARK', 'CARIÑO', 'Mark', 'Cariño'],
    'lower case is capitalised' => ['maria', 'dela cruz', 'Maria', 'Dela Cruz'],
    'extra spaces are removed' => ['  Ana   Liza ', 'De La Cruz', 'Ana Liza', 'De La Cruz'],
]);
