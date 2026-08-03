<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adding a brand must never require a migration — anything specific to one
 * brand belongs in metadata, which is why there are no brand-shaped columns
 * here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // Sister brands share a company (Hot Wheels + Matchbox = Mattel),
            // which is what makes a casting legitimately appear under both.
            $table->string('company')->nullable();
            $table->string('country')->nullable();
            $table->smallInteger('founded_year')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
