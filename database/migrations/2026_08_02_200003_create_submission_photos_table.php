<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('submission_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('submissions')->cascadeOnDelete();

            // Same disk/path pair as the `photos` table, for the same reason: the disk
            // is stored per-row rather than assumed, so a photo captured while the app
            // was pointed at the local disk still resolves after R2 is switched on.
            $table->string('disk')->default('r2');
            $table->string('path');

            // Free-text for the same reason as photos.type — which angles exist is
            // brand-dependent (blister card front/back, box art, loose), and a fixed
            // enum would need a migration to onboard a brand that packages
            // differently. The capture UI offers a shortlist; the column doesn't
            // enforce it.
            $table->string('type')->nullable();
            $table->timestamps();

            $table->index('submission_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submission_photos');
    }
};
