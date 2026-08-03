<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GIN trigram indexes for the Comparison Agent.
 *
 * This is the deterministic half of duplicate detection: similarity('93 Mazda
 * RX-7', '93 MAZDA RX7) stays fast enough to run against the whole catalog on
 * every proposed insert, with no LLM call involved. Meilisearch covers
 * user-facing typo-tolerant search; these cover DB-side matching.
 *
 * Postgres only — the test suite runs on SQLite, which has no pg_trgm.
 */
return new class extends Migration
{
    /** @var array<string, string> table => column */
    private array $indexed = [
        'makes' => 'name',
        'vehicle_models' => 'name',
        'brands' => 'name',
        'series' => 'name',
        'castings' => 'name',
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->indexed as $table => $column) {
            DB::statement("
                CREATE INDEX {$table}_{$column}_trgm
                ON {$table} USING gin ({$column} gin_trgm_ops)
            ");
        }

        // Variation names are the noisiest field the agent matches on, and the
        // one most likely to arrive mis-OCR'd.
        DB::statement('
            CREATE INDEX variations_name_trgm
            ON variations USING gin (name gin_trgm_ops)
        ');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->indexed as $table => $column) {
            DB::statement("DROP INDEX IF EXISTS {$table}_{$column}_trgm");
        }

        DB::statement('DROP INDEX IF EXISTS variations_name_trgm');
    }
};
