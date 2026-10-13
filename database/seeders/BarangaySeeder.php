<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\Municipality;
use Illuminate\Database\Seeder;

class BarangaySeeder extends Seeder
{
    /**
     * Seed the application's municipalities and barangays.
     */
    public function run(): void
    {
        $municipality = Municipality::query()->firstOrCreate(['name' => 'Bacolor', 'province' => 'Pampanga']);

        $barangays = ['San Jose' => 'SJ', 'Cabalantian' => 'CAB'];

        foreach ($barangays as $name => $code) {
            Barangay::query()->updateOrCreate(
                ['name' => $name],
                ['code' => $code, 'municipality_id' => $municipality->id],
            );
        }
    }
}
