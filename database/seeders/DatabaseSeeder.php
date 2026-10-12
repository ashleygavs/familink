<?php

namespace Database\Seeders;

use App\Enums\ResidentStatus;
use App\Enums\RoleName;
use App\Models\Barangay;
use App\Models\Household;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([RoleSeeder::class, BarangaySeeder::class]);

        $sanJose = Barangay::query()->where('name', 'San Jose')->firstOrFail();

        $accounts = [
            ['Ashley Gavino', 'ashleyvenicegavino0728@gmail.com', RoleName::SuperAdmin, null],
            ['Municipal Admin', 'admin@familink.test', RoleName::Admin, null],
            ['Audit Stakeholder', 'stakeholder@familink.test', RoleName::Stakeholder, null],
            ['Barangay Encoder', 'encoder@familink.test', RoleName::User, $sanJose->id],
        ];

        foreach ($accounts as [$name, $email, $role, $barangayId]) {
            User::query()->firstOrNew(['email' => $email])->forceFill([
                'name' => $name,
                'password' => 'password',
                'email_verified_at' => now(),
                'role_id' => Role::query()->where('name', $role->value)->value('id'),
                'barangay_id' => $barangayId,
            ])->save();
        }

        $this->seedTestFamily($sanJose);
    }

    private function seedTestFamily(Barangay $barangay): void
    {
        $household = Household::query()->firstOrCreate(
            ['household_code' => 'HH-000001'],
            ['barangay_id' => $barangay->id, 'street_address' => '12 Rizal Street'],
        );

        $make = fn (string $idNo, array $attributes): Resident => Resident::query()->firstOrCreate(
            ['system_id_no' => $idNo],
            [
                'qr_code_hash' => Str::random(40),
                'household_id' => $household->id,
                'barangay_id' => $barangay->id,
                'last_name' => 'Dela Cruz',
                'status' => ResidentStatus::Approved,
                ...$attributes,
            ],
        );

        $father = $make('FL-00000001', [
            'first_name' => 'Juan', 'birthdate' => '1980-03-14', 'gender' => 'Male',
            'civil_status' => 'Married', 'household_role' => 'Head',
        ]);
        $mother = $make('FL-00000002', [
            'first_name' => 'Maria', 'birthdate' => '1983-07-02', 'gender' => 'Female',
            'civil_status' => 'Married', 'household_role' => 'Spouse', 'spouse_id' => $father->id,
        ]);
        $father->update(['spouse_id' => $mother->id]);

        $make('FL-00000003', [
            'first_name' => 'Pedro', 'birthdate' => '2008-11-20', 'gender' => 'Male',
            'civil_status' => 'Single', 'household_role' => 'Child',
            'father_id' => $father->id, 'mother_id' => $mother->id,
        ]);

        $household->update(['head_resident_id' => $father->id]);
    }
}
