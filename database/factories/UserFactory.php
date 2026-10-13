<?php

namespace Database\Factories;

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Assign the given role, creating it if it does not exist yet.
     */
    public function withRole(RoleName $role): static
    {
        $state = $this->state(fn (array $attributes) => [
            'role_id' => Role::query()->firstOrCreate(['name' => $role->value])->id,
        ]);

        if ($role !== RoleName::Admin) {
            return $state;
        }

        // An admin handles the first barangay by default, which is also where household factories place residents.
        return $state->afterCreating(function (User $user): void {
            $barangay = Barangay::query()->first() ?? Barangay::factory()->create();

            $user->forceFill(['municipality_id' => $barangay->municipality_id])->save();
            $user->managedBarangays()->syncWithoutDetaching([$barangay->id]);
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
