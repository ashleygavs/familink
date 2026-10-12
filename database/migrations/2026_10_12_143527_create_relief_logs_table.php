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
        Schema::create('relief_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('relief_events')->cascadeOnDelete();
            $table->foreignId('resident_id')->constrained()->restrictOnDelete();
            $table->foreignId('household_id')->constrained()->restrictOnDelete();
            $table->foreignId('scanned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('Claimed');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'resident_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('relief_logs');
    }
};
