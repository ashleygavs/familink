<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barangays', function (Blueprint $table) {
            $table->foreignId('municipality_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('municipality_id')->nullable()->after('barangay_id')->constrained()->nullOnDelete();
        });

        // Move the old municipality and province text into real municipality rows.
        $pairs = DB::table('barangays')->select('municipality', 'province')->distinct()->get();

        foreach ($pairs as $pair) {
            $id = DB::table('municipalities')->insertGetId([
                'name' => $pair->municipality,
                'province' => $pair->province,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('barangays')
                ->where('municipality', $pair->municipality)
                ->where('province', $pair->province)
                ->update(['municipality_id' => $id]);
        }

        Schema::table('barangays', function (Blueprint $table) {
            $table->dropColumn(['municipality', 'province']);
        });
    }

    public function down(): void
    {
        Schema::table('barangays', function (Blueprint $table) {
            $table->string('municipality')->default('');
            $table->string('province')->default('');
        });

        foreach (DB::table('municipalities')->get() as $municipality) {
            DB::table('barangays')->where('municipality_id', $municipality->id)
                ->update(['municipality' => $municipality->name, 'province' => $municipality->province]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('municipality_id');
        });

        Schema::table('barangays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('municipality_id');
        });
    }
};
