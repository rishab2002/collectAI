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
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            // Series (e.g. "Night Burnerz") is a brand-scoped, reusable named wave —
            // it has no year of its own. The year a casting sold under a series
            // lives on the Release, since the same series name can recur across
            // multiple years.
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index('brand_id');
        });

        // No hard unique on (brand_id, name): same fuzzy-pipeline reasoning as
        // castings/models — duplicate detection here is the Comparison Agent's job.
        DB::statement('CREATE INDEX series_name_trgm_idx ON series USING gin (name gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('series');
    }
};
