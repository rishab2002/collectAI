<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The catalog tables the Insertion Agent can create rows in, and therefore the
     * ones that need an approval gate.
     *
     * A single photo's pipeline run can propose a record at every level of the
     * hierarchy at once (an unknown casting implies an unknown model, which implies
     * an unknown make), so the gate has to exist at every level or a proposed
     * release would end up hanging off a make nobody approved.
     *
     * Deliberately excluded: `price_histories` and `photos` (observations attached
     * to an already-approved variation, not catalog entities in their own right),
     * and `user_collection_items` / `user_wishlist_items` (user-owned data the
     * pipeline never writes).
     *
     * `makes` and `brands` are included even though CLAUDE.md calls them small,
     * admin-controlled vocabularies. The pipeline shouldn't often propose either
     * one — an unrecognized brand is usually an OCR misread, not a new company —
     * but "shouldn't often" is not "can't", and a genuinely new brand has to be
     * able to enter the catalog without a schema change.
     */
    private const CATALOG_TABLES = [
        'makes',
        'brands',
        'models',
        'castings',
        'casting_versions',
        'series',
        'releases',
        'variations',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::CATALOG_TABLES as $table) {
            // Defaults to 'pending', not 'live'. This is the fail-safe direction: an
            // Insertion Agent that writes a row without setting a status produces an
            // invisible record awaiting review, rather than silently publishing
            // unreviewed AI output to a public catalog. The cost is that every
            // deliberate write (seeders, factories, manual admin entry) has to say
            // 'live' explicitly, which is the right way round for the mistake to run.
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('status')->default('pending');
            });

            // A CHECK constraint rather than a native Postgres enum type, matching the
            // reasoning already applied to `photos.type`: extending a native enum
            // requires a migration and a type alteration, while a CHECK is a cheap
            // constraint swap. 'rejected' is a terminal state rather than a delete, so
            // a resubmitted photo of the same car can be recognized as something an
            // admin already turned down instead of being re-proposed from scratch.
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_status_check CHECK (status IN ('pending', 'live', 'rejected'))");

            // Every row that exists at migration time predates the approval workflow
            // and was created by hand or by the seeder, so it is by definition already
            // reviewed. Without this backfill the column default would retroactively
            // un-publish the entire existing catalog.
            DB::table($table)->update(['status' => 'live']);

            // Partial index: the review queue only ever asks "what isn't live yet",
            // and in a healthy catalog that's a tiny fraction of rows. Indexing only
            // the non-live ones keeps the index small enough to stay cached, and it
            // costs nothing on the overwhelmingly common status = 'live' reads, which
            // are served by the existing per-table indexes.
            DB::statement("CREATE INDEX {$table}_status_unreviewed_idx ON {$table} (status) WHERE status <> 'live'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::CATALOG_TABLES as $table) {
            DB::statement("DROP INDEX IF EXISTS {$table}_status_unreviewed_idx");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_status_check");

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('status');
            });
        }
    }
};
