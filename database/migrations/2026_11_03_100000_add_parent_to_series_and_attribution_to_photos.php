<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two additions the public catalog needs, both nullable, neither touching an
 * existing column.
 *
 * **series.parent_id** — a main line holds sub-series. Collectors browse a
 * brand by its lines ("Mainline", "Car Culture", Matchbox's "1-100") and then by
 * the sub-series inside one ("HW Art Cars", "Sports Cars"), but the wikis name
 * only the sub-series. A line is itself a `series` row with no releases of its
 * own and no parent; a sub-series points at it. Self-referencing rather than a
 * new table because a line *is* a brand-scoped named grouping — the thing
 * `series` already is — and because it needs no brand-specific columns, so a new
 * brand still needs zero schema changes. Null means "not classified yet" for a
 * sub-series, and "top level" for a line. `nullOnDelete` so removing a line
 * un-classifies its sub-series instead of deleting their releases.
 *
 * **photos.license / photos.credit** — imported photos must carry their
 * attribution. A wiki image is reusable only under the licence its own file
 * page states (CC-BY-SA, GFDL, public domain…), and those licences require
 * naming the author and the licence wherever the photo is shown. The source URL
 * goes in `record_sources`, like every other import; these two are what the page
 * prints under the photo. Null for photos we took, which need no credit line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('brand_id')
                ->constrained('series')->nullOnDelete();
            $table->index('parent_id');
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->string('license')->nullable()->after('is_primary');
            $table->string('credit')->nullable()->after('license');
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropColumn(['license', 'credit']);
        });

        Schema::table('series', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
