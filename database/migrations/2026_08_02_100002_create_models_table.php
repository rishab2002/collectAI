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
        Schema::create('models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('make_id')->constrained('makes')->cascadeOnDelete();
            $table->string('name');
            // Chassis code (e.g. FD3S) disambiguates a nameplate that spans multiple
            // real-world generations. Nullable: not every model has a known code.
            $table->string('chassis_code')->nullable();
            $table->timestamps();

            $table->index('make_id');
        });

        // No hard unique constraint on (make_id, name, chassis_code): model records
        // are populated by the OCR/Metadata/Comparison Agent pipeline, which is
        // expected to surface near-duplicates for admin review rather than have the
        // DB silently reject or collapse them. Exact duplicates are caught upstream
        // by the Comparison Agent's trigram/Meilisearch lookup, not by a DB constraint.
        DB::statement('CREATE INDEX models_name_trgm_idx ON models USING gin (name gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('models');
    }
};
