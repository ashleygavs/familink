<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Family matching filters residents by barangay, surname key and an age window, so these keep
     * the lookups fast as the number of residents grows.
     */
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->index(['barangay_id', 'last_name_key']);
            $table->index(['barangay_id', 'birthdate']);
            $table->index('birthdate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->dropIndex(['barangay_id', 'last_name_key']);
            $table->dropIndex(['barangay_id', 'birthdate']);
            $table->dropIndex(['birthdate']);
        });
    }
};
