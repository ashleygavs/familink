<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            // The date of death, when an admin knows it. The resident is deceased when is_deceased is true.
            $table->date('deceased_at')->nullable()->after('is_deceased');
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->dropColumn('deceased_at');
        });
    }
};
