<?php

namespace App\Concerns;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * The new password may not be the one the account already has.
     *
     * @return array<int, Closure>
     */
    protected function differentFromCurrentPasswordRules(User $user): array
    {
        return [
            function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                if (is_string($value) && Hash::check($value, $user->password)) {
                    $fail('validation.password_reused')->translate();
                }
            },
        ];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
