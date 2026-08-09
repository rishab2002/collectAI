<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `variations.tampo` becomes text.
 *
 * Tampo is the printed graphics on a car, and describing them is prose-shaped
 * in a way the other attributes aren't: a convention car's tampo runs to
 * "Ghost blue flames and silver accents on sides, light blue flames on roof,
 * 22nd Annual Collectors Convention logo on trunk, …" — 328 characters, against
 * a varchar(255). Four rows in a 1,183-row sample of real wiki data overflow it,
 * and every other attribute measured stays under 65.
 *
 * Truncating instead would be worse than it looks: tampo is one of the columns
 * the uniqueness index is built from, so a cut-off description doesn't just lose
 * text, it changes which cars count as the same car.
 *
 * The column stays in that index. Postgres caps a btree index entry at about
 * 2,700 bytes, so an implausibly long tampo would be refused at insert — which
 * is the right failure: it would mean someone had pasted an article into the
 * cell, not that a car had that much printing on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The expression index has to come off first: it is built over
        // COALESCE(tampo, ''), and Postgres will not retype a column an
        // expression index depends on.
        DB::statement('DROP INDEX variations_unique_attributes_idx');
        DB::statement('ALTER TABLE variations ALTER COLUMN tampo TYPE text');

        $this->createUniqueIndex();
    }

    public function down(): void
    {
        DB::statement('DROP INDEX variations_unique_attributes_idx');
        // Rows already longer than the old limit would make this fail, which is
        // the honest outcome — rolling back cannot invent a shorter description.
        DB::statement('ALTER TABLE variations ALTER COLUMN tampo TYPE varchar(255)');

        $this->createUniqueIndex();
    }

    private function createUniqueIndex(): void
    {
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
    }
};
