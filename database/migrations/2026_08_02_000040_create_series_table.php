<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Themed release waves. No year column: a series can run for several years and
 * the year is what defines a Release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Globally unique because Series resolves route bindings by slug,
            // so slugs need a brand prefix when two brands reuse a wave name.
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series');
    }
};
