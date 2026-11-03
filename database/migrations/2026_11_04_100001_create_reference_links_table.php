<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A catalog record the admin added from a reference product (Add a car → find
 * the model → use this). The stable key is what the visual model knows that
 * product's photos as, so the record is recognised from the moment it exists.
 *
 * Deliberately not record_sources: that table means "imported from", is shown
 * as attribution on public pages, and is how the app tells imported records
 * from hand-made ones. These are hand-made records; the reference product only
 * pre-filled the form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reference_item_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('linkable');
            $table->string('stable_key')->unique();
            $table->timestamps();

            $table->unique(['linkable_type', 'linkable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_links');
    }
};
