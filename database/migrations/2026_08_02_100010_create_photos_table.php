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
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->constrained('variations')->cascadeOnDelete();
            // Laravel filesystem disk name (e.g. the R2 disk) — kept as a plain
            // string rather than hardcoded, so local/dev and prod can differ.
            $table->string('disk')->default('r2');
            $table->string('path');
            // Free-text rather than a native Postgres enum: brands differ in what
            // photo angles/packaging they have (card front/back, loose, box art),
            // and a fixed enum would need a migration to extend per brand.
            $table->string('type')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('variation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};
