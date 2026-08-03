<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            // Small, admin-controlled vocabulary (Hot Wheels, Matchbox, Corgi, ...).
            // Hard-unique for the same reason as makes.name: a duplicate here is
            // always an error, never a legitimate fuzzy near-match.
            $table->string('name')->unique();
            // Parent company (e.g. Mattel owns Hot Wheels + Matchbox) — lets the
            // AI pipeline and browsing UI reason about "sister brands" per CLAUDE.md.
            $table->string('parent_company')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
