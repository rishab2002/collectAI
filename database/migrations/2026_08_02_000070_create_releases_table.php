<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A specific year a casting was sold under a given brand.
 *
 * brand_id lives here rather than only on the series because series_id is
 * nullable — mainline/basic releases belong to no themed wave — and because
 * this table doubles as the pivot behind Casting::brands() and
 * Brand::castings().
 *
 * Delete behaviour differs per parent on purpose: without its casting a release
 * is meaningless (cascade), a brand with releases should refuse to disappear
 * (restrict), and a removed series just leaves the release mainline (null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('casting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('casting_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('series')->nullOnDelete();
            $table->smallInteger('year');
            $table->string('collector_number')->nullable();
            $table->smallInteger('series_position')->nullable();
            $table->smallInteger('series_size')->nullable();
            $table->string('region')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('status')->default('pending');
            $table->float('confidence_score')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'year']);
            $table->index(['series_id', 'year']);
            $table->index(['casting_id', 'year']);
            $table->index('status');
        });

        // The "add only genuinely missing records" rule, enforced by the
        // database rather than trusted to the Insertion Agent.
        //
        // NULLS NOT DISTINCT (Postgres 15+) is what makes this work: series_id,
        // collector_number and region are all nullable, and under default NULL
        // handling two identical mainline releases would both be accepted
        // because NULL != NULL.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE releases
                ADD CONSTRAINT releases_identity_unique
                UNIQUE NULLS NOT DISTINCT
                (casting_id, brand_id, series_id, year, collector_number, region)
            ');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
