<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('province');
            $table->timestamps();

            $table->unique(['name', 'province']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipalities');
    }
};
