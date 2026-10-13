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
        $barangays = ['San Jose' => 'SJ', 'Cabalantian' => 'CAB'];

        foreach ($barangays as $name => $code) {
            Barangay::query()->updateOrCreate(
                ['name' => $name],
                ['code' => $code, 'municipality' => 'Bacolor', 'province' => 'Pampanga'],
            );
        }
    }
}
