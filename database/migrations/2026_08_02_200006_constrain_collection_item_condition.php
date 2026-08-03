<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pins user_collection_items.condition to the ItemCondition vocabulary.
 *
 * The column went in as free text, so grading was whatever each entry point
 * happened to type ('Mint on card', 'Opened', 'Loose'). Condition is the main
 * thing price history has to be comparable across — a loose car and a carded
 * one are different markets for the same variation — so the values have to be a
 * closed set rather than prose.
 *
 * Same shape as the status gate: string column plus a CHECK, not a native
 * Postgres enum, since adding a case to a native enum needs DDL.
 */
return new class extends Migration
{
    /**
     * Maps the free-text values the factory and any hand entry produced onto
     * the enum's backed values. Anything unrecognised becomes null — the column
     * is nullable, and dropping an ungradeable string is better than either
     * failing the migration or inventing a grade.
     */
    private const BACKFILL = [
        'mint on card' => 'mint',
        'mint' => 'mint',
        'carded' => 'carded',
        'damaged card' => 'damaged',
        'damaged' => 'damaged',
        'opened' => 'opened',
        'opened packaging' => 'opened',
        'loose' => 'loose',
    ];

    public function up(): void
    {
        foreach (self::BACKFILL as $from => $to) {
            DB::table('user_collection_items')
                ->whereRaw('lower(condition) = ?', [$from])
                ->update(['condition' => $to]);
        }

        DB::table('user_collection_items')
            ->whereNotNull('condition')
            ->whereNotIn('condition', array_values(array_unique(self::BACKFILL)))
            ->update(['condition' => null]);

        DB::statement("
            ALTER TABLE user_collection_items
            ADD CONSTRAINT user_collection_items_condition_check
            CHECK (condition IS NULL OR condition IN ('mint', 'carded', 'opened', 'loose', 'damaged'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE user_collection_items DROP CONSTRAINT user_collection_items_condition_check');
    }
};
