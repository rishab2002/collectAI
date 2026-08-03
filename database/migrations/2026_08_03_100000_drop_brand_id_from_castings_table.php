<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Casting is one physical tool and carries no brand. Which brand sold it is a
 * per-release fact, already reachable as release -> series -> brand, since
 * `series.brand_id` exists and `releases.series_id` is non-nullable.
 *
 * `castings.brand_id` was therefore a second, independent assertion of the same
 * thing, with nothing reconciling the two and no defined precedence — and a
 * cross-brand release is legal (that's how shared tooling like Johnny Lightning
 * / Auto World is represented), so the release path can legitimately contradict
 * it. Additive rather than an edit to 100004, per CLAUDE.md.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('castings', function (Blueprint $table) {
            $table->dropIndex(['brand_id']);
            $table->dropConstrainedForeignId('brand_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Comes back **nullable**, unlike the original non-null column: a casting
     * that has no releases has no brand to recover, and the pre-drop values are
     * gone. The backfill below is best-effort — it takes the brand of the
     * earliest series the tooling was released under, which is the closest
     * reconstruction the surviving data supports.
     */
    public function down(): void
    {
        Schema::table('castings', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('id')
                ->constrained('brands')->cascadeOnDelete();

            $table->index('brand_id');
        });

        DB::statement(<<<'SQL'
            UPDATE castings
            SET brand_id = earliest.brand_id
            FROM (
                SELECT DISTINCT ON (casting_versions.casting_id)
                    casting_versions.casting_id,
                    series.brand_id
                FROM casting_versions
                JOIN releases ON releases.casting_version_id = casting_versions.id
                JOIN series ON series.id = releases.series_id
                ORDER BY casting_versions.casting_id, releases.year
            ) AS earliest
            WHERE castings.id = earliest.casting_id
        SQL);
    }
};
