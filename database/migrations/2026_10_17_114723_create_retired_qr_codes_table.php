<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retired_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            // The code that stopped working. Kept so a scan of it is recognised as an old card, not an unknown code.
            $table->string('qr_code_hash', 64)->unique();
            $table->string('reason', 20);
            $table->foreignId('replaced_by_user_id')->constrained('users')->restrictOnDelete();
            // How often the old code was scanned afterwards, which is worth a look after a lost or stolen card.
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->index('resident_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retired_qr_codes');
    }
};
