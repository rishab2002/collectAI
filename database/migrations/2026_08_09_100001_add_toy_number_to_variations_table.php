<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The manufacturer's SKU, printed on the card — Hot Wheels' T9819, J3260.
        //
        // It goes on the variation rather than the release because the source data
        // says so: the 2011 Nightburnerz Nissan 350Z is one release by the natural
        // key (casting version + series + year) and carries two toy numbers, T9819
        // and T9950, one per colour. A toy number is exactly "what distinguishes
        // one unit of a release from another", which is this table's definition.
        //
        // It also closes a hole in the pipeline: the OCR agent already extracts a
        // toy number from packaging as its own field, and it is the most reliable
        // match key a card carries — far more so than a casting name an OCR pass
        // may have misread. With nowhere to store it, the Comparison Agent could
        // never match on it and every incoming card would fall back to fuzzy name
        // matching.
        Schema::table('variations', function (Blueprint $table) {
            $table->string('toy_number')->nullable()->after('base_color');
        });

        // Part of what makes a variation distinct, so it belongs in the uniqueness
        // expression alongside the other distinguishing attributes — two rows alike
        // in every visible way but sold under different SKUs are different
        // variations, and collapsing them would silently discard one.
        //
        // Recreated rather than added to: the index is an expression index, so
        // there is no ALTER that extends it in place.
        DB::statement('DROP INDEX variations_unique_attributes_idx');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX variations_unique_attributes_idx ON variations (
                release_id,
                COALESCE(finish, ''),
                COALESCE(color, ''),
                COALESCE(rim_type, ''),
                COALESCE(tampo, ''),
                COALESCE(interior_color, ''),
                COALESCE(window_tint, ''),
                COALESCE(base_color, ''),
                COALESCE(toy_number, '')
            )
        SQL);

        // Toy numbers come off packaging via OCR, where 8/B and 0/O are the usual
        // misreads, so lookups against this column need the same trigram treatment
        // as releases.collector_number rather than exact matching alone.
        DB::statement('CREATE INDEX variations_toy_number_trigram_idx ON variations USING gin (toy_number gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS variations_toy_number_trigram_idx');
        DB::statement('DROP INDEX variations_unique_attributes_idx');

        Schema::table('variations', function (Blueprint $table) {
            $table->dropColumn('toy_number');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX variations_unique_attributes_idx ON variations (
                release_id,
                COALESCE(finish, ''),
                COALESCE(color, ''),
                COALESCE(rim_type, ''),
                COALESCE(tampo, ''),
                COALESCE(interior_color, ''),
                COALESCE(window_tint, ''),
                COALESCE(base_color, '')
            )
        SQL);
    }
};
