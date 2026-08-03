<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separates "this casting has no real-world counterpart" from "we haven't
 * worked out which car this is yet".
 *
 * Until now both were spelled `model_id IS NULL`, which was fine while castings
 * were entered by hand but breaks the moment the pipeline writes them: OCR
 * routinely yields a casting name whose VehicleModel the Comparison Agent can't
 * match, and every one of those rows would have read as a fantasy casting.
 * Concretely, Casting::approvalDependencies() skips a null model, so an
 * unidentified real car would have been promoted to live with no Make/Model
 * attribution instead of blocking on it.
 *
 * The flag is the positive assertion — is_fantasy = true means someone decided
 * there is no real car. false + null model_id is the "not identified yet" state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('castings', function (Blueprint $table) {
            $table->boolean('is_fantasy')->default(false)->after('model_id');
        });

        // Preserves the old reading of the data exactly: before this column
        // existed, a null model_id *was* how a fantasy casting was recorded.
        DB::statement('UPDATE castings SET is_fantasy = true WHERE model_id IS NULL');

        // A fantasy casting linking a real VehicleModel is a contradiction, and
        // the pipeline is the thing most likely to produce one — the Metadata
        // Agent guessing a model for a casting the Image Agent called original.
        DB::statement('ALTER TABLE castings ADD CONSTRAINT castings_fantasy_has_no_model CHECK (NOT is_fantasy OR model_id IS NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE castings DROP CONSTRAINT castings_fantasy_has_no_model');

        Schema::table('castings', function (Blueprint $table) {
            $table->dropColumn('is_fantasy');
        });
    }
};
