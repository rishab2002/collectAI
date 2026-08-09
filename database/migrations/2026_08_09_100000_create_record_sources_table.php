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
        // Where an imported catalog row came from, and which revision of it we read.
        //
        // Polymorphic for the same reason submission_proposals is: this is audit
        // metadata about how a record arrived, not a fact about the car. Seven
        // columns repeated across eight catalog tables would put provenance in the
        // middle of the domain model, and the two questions an importer actually
        // asks — "what did revision N produce" and "what is now stale" — would each
        // be an eight-way UNION instead of one indexed read.
        //
        // It also draws the line the import decisions turn on: a row with a
        // record_sources entry is something a source *states*, and can go live
        // through the importer. Anything inferred on top of it (the Make/Model
        // split, is_fantasy) is not in the source, so it goes through
        // submission_proposals like any other inference. The two tables are
        // deliberately not merged — provenance and review are different claims.
        Schema::create('record_sources', function (Blueprint $table) {
            $table->id();

            // Written out rather than $table->morphs(), which would add a second
            // compound index on the pair the unique constraint below already
            // covers — the same call made on submission_proposals.
            $table->string('sourceable_type');
            $table->unsignedBigInteger('sourceable_id');

            // Which body of data this came from, e.g. 'hotwheels_fandom'.
            // Deliberately un-CHECKed: onboarding a new source must not require a
            // migration, exactly as onboarding a new brand must not.
            $table->string('source');

            // This record's identity *within* that source, and the key that makes
            // re-import idempotent. Must be unique per record rather than per page:
            // a casting article yields one casting and many releases, so they are
            // keyed 'page:12345' and 'page:12345:row:7'. Keying both to the page
            // would make the second row collide with the first and a re-import
            // overwrite the casting with a release. RecordSource::pageKey() and
            // ::rowKey() build these so the convention is code, not prose.
            $table->string('source_key');

            // Where a human can go to check the claim. Non-nullable on purpose:
            // the Hot Wheels wiki is CC-BY-SA, so attribution is a licence
            // obligation rather than a nicety, and a row with no URL is one the
            // public catalog cannot attribute. Making it required means the
            // importer cannot create an unattributable record even by mistake.
            $table->string('source_url');

            // The source revision these values were read from, so a later run can
            // tell "unchanged" from "not looked at since". Nullable because not
            // every source is versioned — a MediaWiki dump is, a CSV export isn't.
            $table->unsignedBigInteger('source_revision_id')->nullable();

            // When the import last read this record, which is not when the row was
            // created: a re-import that finds nothing changed still moves
            // imported_at forward while created_at stays put. Staleness sweeps read
            // this column, which is why it exists alongside timestamps().
            $table->timestamp('imported_at');

            $table->timestamps();

            // The idempotency constraint. A second import run resolves an existing
            // record by (source, source_key) and updates it; without this being a
            // hard guarantee, "add only genuinely missing records" would be a
            // convention every future importer has to remember to honour.
            $table->unique(['source', 'source_key']);

            // One provenance row per catalog record. A record has a single origin
            // story — corroboration from a second source is evidence about a fact,
            // not another claim on where the row came from.
            $table->unique(['sourceable_type', 'sourceable_id']);

            // "Everything from revision N", for re-running or rolling back one
            // import pass.
            $table->index(['source', 'source_revision_id']);

            // "Everything not read since X", which is how a refresh run picks its
            // work without walking the whole catalog.
            $table->index(['source', 'imported_at']);
        });

        // A source key that doesn't identify anything is the failure this table
        // exists to prevent — it would let two unrelated records claim the same
        // origin and let a re-import overwrite the wrong one. Postgres treats ''
        // as a perfectly good unique value, so the constraint has to say so.
        DB::statement("ALTER TABLE record_sources ADD CONSTRAINT record_sources_source_key_not_blank CHECK (btrim(source_key) <> '')");
        DB::statement("ALTER TABLE record_sources ADD CONSTRAINT record_sources_source_not_blank CHECK (btrim(source) <> '')");

        // Same argument as source_url being non-nullable: an empty string would
        // satisfy the column and still attribute nothing.
        DB::statement("ALTER TABLE record_sources ADD CONSTRAINT record_sources_source_url_not_blank CHECK (btrim(source_url) <> '')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_sources');
    }
};
