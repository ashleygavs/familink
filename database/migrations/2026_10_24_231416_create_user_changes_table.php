<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_changes', function (Blueprint $table) {
            $table->id();
            // A deleted account keeps its history: the link is cleared and the name and email are kept here.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The barangay of an encoder, so an admin sees only the changes of the encoders they handle.
            $table->foreignId('barangay_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_name');
            $table->string('target_email');
            // Whoever made the change stays on record, so an account with changes is deactivated, never deleted.
            $table->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('summary', 1000);
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['barangay_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_changes');
    }
};
