<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed the application's roles.
     */
    public function run(): void
    {
        $descriptions = [
            RoleName::SuperAdmin->value => 'Manages users and has full system access.',
            RoleName::Admin->value => 'Municipal staff who approve residents and manage relief events.',
            RoleName::Stakeholder->value => 'Auditor with read-only access to reports and family trees.',
            RoleName::User->value => 'Barangay encoder who registers residents and scans QR codes.',
        ];

        foreach ($descriptions as $name => $description) {
            Role::query()->updateOrCreate(['name' => $name], ['description' => $description]);
        }
    }
}
