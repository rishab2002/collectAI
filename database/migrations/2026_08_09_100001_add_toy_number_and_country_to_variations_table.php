<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the two facts every wiki version row states, and the catalog had
 * nowhere to put, a home — and makes them count towards what distinguishes one
 * variation from another.
 *
 * Both were found by running the wiki parser over the first sixty casting
 * articles the 2011 index links to, 1,183 version rows. Against the existing
 * uniqueness index, 11 of those rows collided with a sibling in the same
 * release: identical on every distinguishing column, so the second insert would
 * fail and the car would be lost. Toy number resolves three of them and country
 * another three, leaving five that are genuinely the same car listed twice and
 * should collapse.
 *
 * Toy number is also the number printed on the base and the one collectors
 * search by, so a catalog built from this source without it is missing the
 * field its users would reach for first. It is not Hot Wheels-specific in any
 * way that matters — every brand prints a part number — so this does not breach
 * the rule that onboarding a brand needs no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variations', function (Blueprint $table) {
            // The manufacturer's part number for this exact car — "T9672". Not
            // unique on its own: the wiki shows one toy number covering two
            // rows that differ by wheel, and one release reusing a number
            // across colours.
            $table->string('toy_number')->nullable()->after('base_color');

            // Country of assembly, which collectors track as a base variant in
            // its own right and which the packaging states.
            $table->string('country')->nullable()->after('toy_number');
        });

        // The index has to be rebuilt rather than added to: it is the whole
        // definition of variation identity, and leaving the new columns out
        // would mean two cars with different toy numbers still collide.
        //
        // Safe to do in place — both columns are NULL on every existing row, so
        // COALESCE(col, '') leaves each row's key exactly what it was.
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
                COALESCE(toy_number, ''),
                COALESCE(country, '')
            )
        SQL);

        // Toy number is what a collector types in from the base of a car, and
        // what the OCR agent reads off a card. Trigram rather than plain: it is
        // short, hand-copied and frequently misread by one character.
        DB::statement('CREATE INDEX variations_toy_number_trgm_idx ON variations USING gin (toy_number gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX variations_toy_number_trgm_idx');
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
                COALESCE(base_color, '')
            )
        SQL);

        Schema::table('variations', function (Blueprint $table) {
            $table->dropColumn(['toy_number', 'country']);
        });
    }
};
