<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relief_events', function (Blueprint $table) {
            $table->foreignId('municipality_id')->nullable()->after('title')->constrained()->restrictOnDelete();
        });

        // Events made before municipalities existed belong to the first municipality.
        $firstMunicipality = DB::table('municipalities')->orderBy('id')->value('id');

        if ($firstMunicipality !== null) {
            DB::table('relief_events')->whereNull('municipality_id')->update(['municipality_id' => $firstMunicipality]);
        }
    }

    public function down(): void
    {
        Schema::table('relief_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('municipality_id');
        });
    }
};
