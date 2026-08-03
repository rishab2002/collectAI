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
        Schema::create('castings', function (Blueprint $table) {
            $table->id();
            // A casting is brand-specific tooling: each row belongs to exactly one
            // brand. Reuse of the same real Model across sister brands is modeled
            // as separate Casting rows (one per brand), not a shared casting row.
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            // Null for fantasy/original castings with no real-world basis — see
            // CLAUDE.md's Make/Model splitting rules. Make is reached via
            // model->make_id rather than duplicated here, so there's no risk of a
            // casting's make and model getting out of sync.
            $table->foreignId('model_id')->nullable()->constrained('models')->nullOnDelete();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('brand_id');
            $table->index('model_id');
        });

        // No hard unique constraint on (brand_id, name): same reasoning as models —
        // this table is populated via the OCR/Metadata/Comparison Agent pipeline,
        // which needs to see near-duplicate candidates rather than have them
        // silently blocked or merged at the DB layer.
        DB::statement('CREATE INDEX castings_name_trgm_idx ON castings USING gin (name gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('castings');
    }
};
