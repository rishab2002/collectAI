<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            // Points at the specific tooling revision (not the abstract Casting),
            // since a retool's releases belong to that physical version.
            $table->foreignId('casting_version_id')->constrained('casting_versions')->cascadeOnDelete();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            // Descriptive metadata only (format varies by brand) — not part of the
            // identity key, per CLAUDE.md: "Release: defined by the year a
            // casting/color combo was sold in stores under a given series."
            $table->string('collector_number')->nullable();
            $table->timestamps();

            $table->index('series_id');
            // The natural key: this casting version, in this series, in this year.
            $table->unique(['casting_version_id', 'series_id', 'year']);
        });

        DB::statement('ALTER TABLE releases ADD CONSTRAINT releases_year_range CHECK (year BETWEEN 1900 AND 2100)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
