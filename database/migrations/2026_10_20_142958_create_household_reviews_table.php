<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_reviews', function (Blueprint $table) {
            $table->id();
            // A pair is stored once, with the lower household id first, so it is the same whichever way it was found.
            $table->foreignId('first_household_id')->constrained('households')->cascadeOnDelete();
            $table->foreignId('second_household_id')->constrained('households')->cascadeOnDelete();
            $table->string('reason', 500);
            // Whoever reviewed it stays on record, so an account with reviews is deactivated, never deleted.
            $table->foreignId('reviewed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['first_household_id', 'second_household_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_reviews');
    }
};
