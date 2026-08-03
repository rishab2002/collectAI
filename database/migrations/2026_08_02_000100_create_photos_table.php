<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Photos live on R2 through the S3 driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            // Persisted rather than assumed so existing rows keep resolving if
            // the default disk is ever repointed.
            $table->string('disk')->nullable();
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->boolean('is_primary')->default(false);
            // Marks the packaging shots the Image/OCR agents read, so a
            // proposed record can be traced back to its source during review.
            $table->boolean('is_ai_source')->default(false);
            $table->string('attribution')->nullable();
            $table->timestamps();

            $table->index(['variation_id', 'is_primary']);
            $table->index('is_ai_source');
        });

        // Variation::primaryPhoto() is a hasOne, so let the database guarantee
        // there is at most one. Partial index syntax differs across drivers;
        // on SQLite (tests only) the application is left to enforce it.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('
                CREATE UNIQUE INDEX photos_one_primary_per_variation
                ON photos (variation_id) WHERE is_primary
            ');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};
