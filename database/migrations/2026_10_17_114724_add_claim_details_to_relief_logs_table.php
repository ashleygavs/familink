<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relief_logs', function (Blueprint $table) {
            // How the resident was identified: the QR code, or a typed resident ID.
            $table->string('entry_method', 10)->default('qr')->after('status');
            // Someone else collected the relief for the resident, with the authorization they showed.
            $table->boolean('claimed_by_representative')->default(false)->after('entry_method');
            $table->string('representative_name', 150)->nullable()->after('claimed_by_representative');
            $table->string('representative_relationship', 50)->nullable()->after('representative_name');
            $table->boolean('representative_verified')->default(false)->after('representative_relationship');
        });
    }

    public function down(): void
    {
        Schema::table('relief_logs', function (Blueprint $table) {
            $table->dropColumn([
                'entry_method', 'claimed_by_representative', 'representative_name',
                'representative_relationship', 'representative_verified',
            ]);
        });
    }
};
