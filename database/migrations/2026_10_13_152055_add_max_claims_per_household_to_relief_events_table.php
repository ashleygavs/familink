<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relief_events', function (Blueprint $table) {
            // How many members of one household may claim in a Per Resident event. Null means no limit.
            $table->unsignedSmallInteger('max_claims_per_household')->nullable()->after('allocation_type');
        });
    }

    public function down(): void
    {
        Schema::table('relief_events', function (Blueprint $table) {
            $table->dropColumn('max_claims_per_household');
        });
    }
};
