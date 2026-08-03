<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What makes one unit of a release different from another.
 *
 * These are columns rather than a free-text note so the Comparison Agent can
 * diff them field by field. Deliberately no uniqueness constraint across the
 * distinguishing columns: tampo and finish descriptions are free text and vary
 * by source, so a hard constraint would reject legitimate records. Duplicate
 * detection here is the Comparison Agent's job, via the trigram indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('body_color')->nullable();
            $table->string('finish')->nullable();
            $table->string('wheel_type')->nullable();
            $table->text('tampo')->nullable();
            $table->string('interior_color')->nullable();
            $table->string('window_color')->nullable();
            $table->string('base_color')->nullable();
            $table->string('base_material')->nullable();
            $table->string('country_of_manufacture')->nullable();
            $table->boolean('is_chase')->default(false);
            $table->text('notes')->nullable();
            // Brand-specific markings (Super Treasure Hunt flags, exclusives)
            // go here so onboarding a brand never needs a migration.
            $table->jsonb('metadata')->nullable();
            $table->string('status')->default('pending');
            $table->float('confidence_score')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['release_id', 'status']);
            $table->index('is_chase');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variations');
    }
};
