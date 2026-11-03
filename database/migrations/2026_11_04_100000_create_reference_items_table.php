<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products listed by makers and guides the owner has written permission from
 * (HWPriceGuide, TSM/Mini GT, Inno64, Para64, Horizon Diecast) — a private
 * reference list, never shown publicly.
 *
 * Two uses, both stated by the owner's permissions: labels and images for
 * training the visual model (the images are fetched, embedded and discarded —
 * their URLs are kept, not their bytes), and suggestions on the admin's Add a
 * car form, where picking an item pre-fills the form and links the new car to
 * it so the trained model recognises it.
 *
 * Not catalog records: nothing here is live, approvable or browsable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_items', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);          // hwpriceguide | tsm | inno64 | para64 | horizon
            $table->string('source_key');            // the item's own id there: slug, SKU, item code
            $table->string('brand')->nullable();
            $table->string('line')->nullable();      // main series: "Car Culture"
            $table->string('series')->nullable();    // sub-series: "AutoStrasse"
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('name');                  // the car, as listed
            $table->string('item_code')->nullable(); // SKU / item number / toy number
            $table->string('scale', 16)->nullable();
            $table->jsonb('image_urls');             // for training only; never displayed
            $table->string('page_url')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_key']);
            $table->index('item_code');
            $table->index(['brand', 'line', 'series']);
        });

        DB::statement('CREATE INDEX reference_items_name_trgm ON reference_items USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_items');
    }
};
