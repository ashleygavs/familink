<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Records relatives a resident named when registering (a cousin, an uncle, a grandparent...) that
     * cannot be derived from the parent, child and spouse links alone.
     */
    public function up(): void
    {
        Schema::create('relative_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();
            $table->foreignId('relative_id')->constrained('residents')->cascadeOnDelete();
            $table->string('relation', 20);
            $table->timestamps();

            $table->unique(['resident_id', 'relative_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('relative_links');
    }
};
