<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A toy manufacturer's tooling of a vehicle.
 *
 * make_id / vehicle_model_id are nullable and nullOnDelete: losing the
 * real-world attribution must never delete the casting itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('castings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('make_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            // The company owning the tooling (Mattel), not the brand — the same
            // tooling sells under sister brands, so brand lives on the release.
            $table->string('company')->nullable();
            $table->string('designer')->nullable();
            $table->string('scale')->nullable();
            $table->smallInteger('debut_year')->nullable();
            // Explicit rather than inferred from a null make_id, so "no real
            // counterpart" stays distinguishable from "not identified yet".
            $table->boolean('is_fantasy')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->string('status')->default('pending');
            $table->float('confidence_score')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            // Serves Casting::unattributed() — the real-world castings still
            // missing their Make/Model split.
            $table->index(['is_fantasy', 'vehicle_model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('castings');
    }
};
