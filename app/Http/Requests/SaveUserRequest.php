<?php

namespace App\Http\Requests;

use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            ? $this->user()->can('update', $target)
            : $this->user()->can('create', User::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $target = $this->route('user');
        $target = $target instanceof User ? $target : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target)],
            'password' => [$target === null ? 'required' : 'nullable', 'string', Password::defaults()],
            'role' => ['required', Rule::in($this->allowedRoles($target))],
            'municipality_id' => ['required_if:role,'.RoleName::Admin->value, 'nullable', 'integer', 'exists:municipalities,id'],
            'managed_barangay_ids' => ['required_if:role,'.RoleName::Admin->value, 'array'],
            'managed_barangay_ids.*' => ['integer', Rule::exists('barangays', 'id')->where('municipality_id', $this->input('municipality_id'))],
            'barangay_id' => ['required_if:role,'.RoleName::User->value, 'nullable', 'integer', 'exists:barangays,id'],
        ];
    }

    /**
     * Admins only create encoders, and nobody changes their own role.
     *
     * @return list<string>
     */
    private function allowedRoles(?User $target): array
    {
        if ($target !== null && $target->is($this->user())) {
            return [$target->role->name ?? ''];
        }

        return $this->user()->hasRole(RoleName::SuperAdmin) ? RoleName::values() : [RoleName::User->value];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $barangayId = $this->integer('barangay_id');

            if ($this->input('role') === RoleName::User->value && $barangayId > 0 && ! $this->user()->canAccessBarangay($barangayId)) {
                $validator->errors()->add('barangay_id', 'You can only assign encoders to barangays you handle.');
            }

            if ($this->input('role') === RoleName::Admin->value && Barangay::query()->whereKey($this->input('managed_barangay_ids', []))->count() === 0) {
                $validator->errors()->add('managed_barangay_ids', 'Choose at least one barangay for this admin.');
            }
        }];
    }
}
