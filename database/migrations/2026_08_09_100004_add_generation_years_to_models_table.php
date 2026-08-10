<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a model row the production years of the generation it describes.
 *
 * `chassis_code` was already meant to be the thing that tells one generation of
 * a nameplate from another — the create migration names
 * (make_id, name, chassis_code) as the natural key. It couldn't do that job on
 * its own for two reasons the first real import made obvious:
 *
 * 1. **Not every generation has a code.** Corvette generations are C1–C8 and
 *    Mustangs have chassis codes, but plenty of nameplates have neither, so
 *    keying on the code alone still collapses them. Every generation has years.
 * 2. **A bare code is unreadable.** "C3" means nothing without "1968–1982"
 *    beside it, and a catalog that can't render the distinction it just made is
 *    not much better than one that never made it.
 *
 * Together the three columns give a generation an identity (the start year — no
 * two generations of one nameplate begin in the same year) and a label the
 * public catalog can print: "Corvette · C3 (1968–1982)".
 *
 * Still no hard unique constraint, for the same reason the create migration
 * gives: these rows come from the AI pipeline, and near-duplicates are for the
 * Comparison Agent to surface, not the database to reject.
 *
 * Nullable because "which generation" is an inference like any other. Below the
 * confidence threshold it stays null, which honestly reads as "this nameplate's
 * generation is not established yet" rather than asserting a wrong one — the
 * failure this whole migration exists to fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('models', function (Blueprint $table) {
            // smallInteger: a model year fits in 5 digits with centuries to
            // spare, and these are compared and printed, never summed.
            $table->smallInteger('generation_start_year')->nullable()->after('chassis_code');

            // Null also means "still in production", which is why this can't be
            // required alongside the start year.
            $table->smallInteger('generation_end_year')->nullable()->after('generation_start_year');
        });

        // A generation cannot end before it began. Cheap to state, and the kind
        // of thing a swapped pair of arguments produces silently.
        DB::statement(<<<'SQL'
            ALTER TABLE models ADD CONSTRAINT models_generation_years_ordered CHECK (
                generation_start_year IS NULL
                OR generation_end_year IS NULL
                OR generation_end_year >= generation_start_year
            )
        SQL);

        // The lookup the identifier does on every casting: this make's rows for
        // this nameplate, split by generation.
        DB::statement('CREATE INDEX models_generation_idx ON models (make_id, name, generation_start_year)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS models_generation_idx');
        DB::statement('ALTER TABLE models DROP CONSTRAINT IF EXISTS models_generation_years_ordered');

        Schema::table('models', function (Blueprint $table) {
            $table->dropColumn(['generation_start_year', 'generation_end_year']);
        });
    }
};
