<?php

use App\Models\Resident;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Listed relatives are declared by a family member before they register themselves,
     * so their household, barangay and civil status can be unknown.
     */
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->unsignedBigInteger('household_id')->nullable()->change();
            $table->unsignedBigInteger('barangay_id')->nullable()->change();
            $table->string('civil_status')->nullable()->change();
        });

        Schema::table('residents', function (Blueprint $table) {
            $table->string('maiden_name')->nullable()->after('last_name');
            $table->boolean('birthdate_estimated')->default(false)->after('birthdate');
            $table->boolean('is_deceased')->default(false)->after('status');
            $table->foreignId('listed_by_resident_id')->nullable()->after('approved_by_user_id')->constrained('residents')->nullOnDelete();
            $table->string('first_name_key', 100)->nullable();
            $table->string('middle_name_key', 100)->nullable();
            $table->string('last_name_key', 100)->nullable();
            $table->string('maiden_name_key', 100)->nullable();
            $table->index(['last_name_key', 'first_name_key']);
            $table->index('middle_name_key');
            $table->index('maiden_name_key');
        });

        DB::table('residents')->orderBy('id')->each(function (object $resident): void {
            DB::table('residents')->where('id', $resident->id)->update([
                'first_name_key' => Resident::normalizeName($resident->first_name),
                'middle_name_key' => Resident::normalizeName($resident->middle_name),
                'last_name_key' => Resident::normalizeName($resident->last_name),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->dropIndex(['last_name_key', 'first_name_key']);
            $table->dropIndex(['middle_name_key']);
            $table->dropIndex(['maiden_name_key']);
            $table->dropConstrainedForeignId('listed_by_resident_id');
            $table->dropColumn([
                'maiden_name', 'birthdate_estimated', 'is_deceased',
                'first_name_key', 'middle_name_key', 'last_name_key', 'maiden_name_key',
            ]);
        });
    }
};
