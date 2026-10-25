<?php

use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $rules
 */
function messageFor(array $data, array $rules, string $field): string
{
    return Validator::make($data, $rules)->errors()->first($field);
}

it('asks for a missing field in plain words, using the everyday name of the field', function () {
    expect(messageFor([], ['email' => 'required'], 'email'))->toBe('Please fill in the email address.')
        ->and(messageFor([], ['birthdate' => 'required'], 'birthdate'))->toBe('Please fill in the birthdate.')
        ->and(messageFor([], ['mother_id' => 'required'], 'mother_id'))->toBe("Please fill in the mother's record.");
});

it('explains a wrong value without technical words', function () {
    expect(messageFor(['email' => 'nope'], ['email' => 'email'], 'email'))->toBe('Please enter a real email address, like name@example.com.')
        ->and(messageFor(['birthdate' => 'x'], ['birthdate' => 'date'], 'birthdate'))->toBe('Please enter a real date for birthdate.')
        ->and(messageFor(['password' => 'abc'], ['password' => 'min:12'], 'password'))->toBe('The password needs at least 12 characters.')
        ->and(messageFor(['street_address' => str_repeat('a', 300)], ['street_address' => 'max:255'], 'street_address'))->toBe('The street address is too long. Please use 255 characters or fewer.')
        ->and(messageFor(['gender' => 'Other'], ['gender' => 'in:Male,Female'], 'gender'))->toBe('Please choose a sex from the list.')
        ->and(messageFor(['password' => 'abc', 'password_confirmation' => 'xyz'], ['password' => 'confirmed'], 'password'))->toBe('The two password entries do not match. Please type it again.');
});

it('says when a value is already used', function () {
    $user = User::factory()->create(['email' => 'taken@example.com']);

    expect(messageFor(['email' => $user->email], ['email' => 'unique:users,email'], 'email'))->toBe('That email address is already used. Please use a different one.');
});

it('never shows the stock Laravel sentences', function () {
    $stock = ['field is required', 'field must', 'has already been taken', 'selected', 'is invalid', 'must be a string', 'must be an integer'];
    $sentences = collect(trans('validation'))->except(['custom', 'attributes'])->flatten();

    // A rule the app does not use may still carry its stock text, so only the rules this app actually uses are checked.
    $used = ['required', 'required_if', 'string', 'integer', 'max', 'min', 'email', 'date', 'unique', 'exists', 'in', 'boolean', 'accepted', 'confirmed', 'different', 'distinct', 'regex', 'between', 'before_or_equal', 'after_or_equal', 'current_password'];
    $checked = collect($used)->flatMap(fn ($rule) => (array) trans("validation.{$rule}"))->flatten();

    foreach ($checked as $sentence) {
        foreach ($stock as $phrase) {
            expect(str_contains($sentence, $phrase))->toBeFalse("'{$sentence}' still has stock wording '{$phrase}'");
        }
    }

    expect($sentences)->not->toBeEmpty();
});

it('words the login and password-change failures kindly and without saying which part was wrong', function () {
    expect(trans('auth.failed'))->toBe('The email or password is not correct. Please check and try again.')
        ->and(trans('auth.throttle', ['seconds' => 30]))->toBe('Too many tries. Please wait 30 seconds and try again.');
});

it('asks to choose, not fill in, when a dropdown is left empty', function () {
    expect(messageFor([], ['barangay_id' => 'required'], 'barangay_id'))->toBe('Please choose a barangay.')
        ->and(messageFor([], ['role' => 'required'], 'role'))->toBe('Please choose a role.')
        ->and(messageFor([], ['first_name' => 'required'], 'first_name'))->toBe('Please fill in the first name.');
});
