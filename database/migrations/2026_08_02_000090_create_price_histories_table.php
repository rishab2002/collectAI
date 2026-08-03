<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only price observations. There is no "current price" column: charts
 * read the series, so nothing has to be recomputed on write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('source_listing_id')->nullable();
            $table->text('url')->nullable();
            $table->decimal('price', 12, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('condition')->nullable();
            // Asking prices skew averages, so sold vs listed is a first-class
            // distinction rather than something inferred from the source.
            $table->boolean('is_sold')->default(false);
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['variation_id', 'observed_at']);
            $table->index(['variation_id', 'condition', 'is_sold']);

            // Makes re-crawling idempotent while still allowing the same
            // listing to be re-observed later at a different price. Manual
            // entries leave source_listing_id null and are unconstrained,
            // since NULLs compare distinct here.
            $table->unique(['source', 'source_listing_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_histories');
    }
};
