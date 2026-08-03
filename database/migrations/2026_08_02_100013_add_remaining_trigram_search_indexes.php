<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * makes.name and brands.name are hard-unique and small controlled
     * vocabularies, so they don't need trigram search for the Comparison Agent —
     * but brands.name still benefits from typo-tolerant admin/search lookups, and
     * releases.collector_number is an OCR-extracted field prone to misreads
     * (e.g. "l" vs "1"), so both get GIN trigram indexes here. models/castings/
     * series already got theirs in their own create-table migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX brands_name_trgm_idx ON brands USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX releases_collector_number_trgm_idx ON releases USING gin (collector_number gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS brands_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS releases_collector_number_trgm_idx');
    }
};
