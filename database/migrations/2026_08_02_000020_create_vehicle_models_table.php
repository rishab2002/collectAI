<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real-world vehicles. Table is vehicle_models rather than models so the
 * Eloquent class does not have to be called Model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('make_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            // The same nameplate spans generations, so the chassis code is what
            // actually identifies the car: RX-7 (FD3S) vs RX-7 (FC3S).
            $table->string('chassis_code')->nullable();
            $table->string('generation')->nullable();
            $table->smallInteger('production_start_year')->nullable();
            $table->smallInteger('production_end_year')->nullable();
            $table->string('body_style')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['make_id', 'name']);
            $table->index('chassis_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_models');
    }
};
