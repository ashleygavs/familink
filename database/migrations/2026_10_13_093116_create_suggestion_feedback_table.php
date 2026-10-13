<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every yes, no, not sure or "related another way" answer to a suggested relative. It is only
     * metadata: it never changes a family link, but it lets rejected suggestions stay hidden and shows
     * which kinds of suggestion people actually confirm.
     */
    public function up(): void
    {
        Schema::create('suggestion_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('residents')->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('decision', 15);
            $table->string('reclassified_as', 20)->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->string('confidence', 10)->nullable();
            $table->json('reasons')->nullable();
            $table->string('context', 15);
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['resident_id', 'candidate_id', 'role']);
            $table->index(['role', 'decision']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suggestion_feedback');
    }
};
