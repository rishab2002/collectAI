<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('makes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Unique globally because Make resolves route bindings by slug.
            $table->string('slug')->unique();
            $table->string('country')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('makes');
    }
};
