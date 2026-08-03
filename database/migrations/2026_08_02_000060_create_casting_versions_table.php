<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A retool of a casting — the mould changed, as opposed to a Variation where
 * only the finish changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('casting_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('casting_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->smallInteger('version_number')->nullable();
            $table->smallInteger('introduced_year')->nullable();
            $table->text('changes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['casting_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casting_versions');
    }
};
