<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each variation's price was last looked up, and what came of it.
 *
 * Separate from price_histories because "we looked and found nothing" is not a
 * price, and without remembering it a car eBay has never heard of would be
 * re-queried every day forever, spending the daily call budget on cars that
 * can't be priced. Separate from `variations` for the same reason provenance
 * is: bookkeeping stays out of the domain tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->unique()->constrained('variations')->cascadeOnDelete();
            $table->timestamp('checked_at');
            // sold | listing | msrp | none | error
            $table->string('outcome', 16);
            $table->unsignedSmallInteger('calls')->default(0);
            $table->timestamps();

            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_checks');
    }
};
