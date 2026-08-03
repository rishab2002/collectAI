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
        // Modeled as a child table rather than a self-referencing parent_casting_id +
        // version column on `castings` itself: a retool is a physically distinct
        // tooling with its own releases, but still shares the same brand/model/name
        // identity. A child table lets every Release point at the exact tooling
        // revision it came from (casting_version_id) while brand/model stay owned
        // once by the parent Casting row, with no duplication or sync risk across
        // versions. Every casting gets at least one row here (version_number = 1),
        // created alongside the Casting itself by the application, even if it's
        // never retooled.
        Schema::create('casting_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('casting_id')->constrained('castings')->cascadeOnDelete();
            $table->unsignedSmallInteger('version_number')->default(1);
            $table->unsignedSmallInteger('retool_year')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['casting_id', 'version_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('casting_versions');
    }
};
