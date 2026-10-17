<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relief_event_changes', function (Blueprint $table) {
            $table->id();
            // A deleted draft keeps its history, so the event link is cleared and the title and place are kept here.
            $table->foreignId('relief_event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('municipality_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_title');
            // Whoever made the change stays on record, so an account with changes is deactivated, never deleted.
            $table->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('summary', 1000);
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['relief_event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relief_event_changes');
    }
};
