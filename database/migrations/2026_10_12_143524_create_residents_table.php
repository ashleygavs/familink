<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->string('system_id_no')->unique();
            $table->string('qr_code_hash')->unique();
            $table->foreignId('household_id')->constrained()->restrictOnDelete();
            $table->foreignId('barangay_id')->constrained()->restrictOnDelete();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->date('birthdate');
            $table->string('gender');
            $table->string('civil_status');
            $table->string('household_role')->nullable();
            $table->foreignId('spouse_id')->nullable()->constrained('residents')->nullOnDelete();
            $table->foreignId('father_id')->nullable()->constrained('residents')->nullOnDelete();
            $table->foreignId('mother_id')->nullable()->constrained('residents')->nullOnDelete();
            $table->string('status')->default('Pending')->index();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('residents');
    }
};
