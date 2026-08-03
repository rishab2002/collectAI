<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * pg_trgm powers the Comparison Agent's fuzzy duplicate detection — without it
 * the trigram indexes added at the end of this batch cannot be created.
 *
 * Guarded by driver because the test suite runs on SQLite in-memory (see
 * phpunit.xml) while dev and production run Postgres.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
    }
};
