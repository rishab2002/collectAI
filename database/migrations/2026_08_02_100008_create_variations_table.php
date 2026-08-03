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
        Schema::create('variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained('releases')->cascadeOnDelete();
            $table->string('finish')->nullable();
            $table->string('color')->nullable();
            $table->string('rim_type')->nullable();
            $table->string('tampo')->nullable();
            $table->string('interior_color')->nullable();
            $table->string('window_tint')->nullable();
            $table->string('base_color')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('release_id');
        });

        // A plain UNIQUE constraint across the distinguishing columns wouldn't
        // actually stop duplicates: in Postgres, NULL is never equal to NULL, so two
        // rows that are identical except both having a NULL `tampo` (say) would both
        // satisfy a standard unique constraint. Every one of these attributes is
        // optional (not every variation has a distinct interior color, tint, etc.),
        // so NULL collisions are the common case, not the exception. Coalescing to
        // '' before comparing closes that hole and enforces the real invariant:
        // this exact attribute combination is unique per release.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX variations_unique_attributes_idx ON variations (
                release_id,
                COALESCE(finish, ''),
                COALESCE(color, ''),
                COALESCE(rim_type, ''),
                COALESCE(tampo, ''),
                COALESCE(interior_color, ''),
                COALESCE(window_tint, ''),
                COALESCE(base_color, '')
            )
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('variations');
    }
};
