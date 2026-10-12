<?php

namespace Database\Seeders;

use App\Models\Barangay;
use Illuminate\Database\Seeder;

class BarangaySeeder extends Seeder
{
    /**
     * Seed the application's barangays.
     */
    public function run(): void
    {
        foreach (['San Jose', 'Cabalantian'] as $name) {
            Barangay::query()->updateOrCreate(
                ['name' => $name],
                ['municipality' => 'Bacolor', 'province' => 'Pampanga'],
            );
        }
    }
}
