<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            // Whoever made the change stays on record, so an account with changes is deactivated, never deleted.
            $table->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('summary');
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['resident_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_changes');
    }
};
