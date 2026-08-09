<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an imported catalog row came from, and which revision of that source
     * it was read out of.
     *
     * Polymorphic for the same reason submission_proposals is: this is audit
     * metadata about how a record got here, not a fact about the car. Seven
     * columns repeated across the eight catalog tables would put provenance in
     * the middle of the domain model, and the two questions the importer
     * actually asks — "everything from revision N" and "everything now stale" —
     * would be an eight-way UNION instead of one indexed read.
     *
     * It is also what makes a re-import idempotent and what carries CC-BY-SA
     * attribution back to the page a row was read from.
     */
    public function up(): void
    {
        Schema::create('record_sources', function (Blueprint $table) {
            $table->id();

            // Written out rather than $table->morphs(), which would add its own
            // compound index on the same two columns the unique below already
            // indexes — same call as submission_proposals.
            $table->string('sourceable_type');
            $table->unsignedBigInteger('sourceable_id');

            // Which body of data this came from, e.g. 'hotwheels_fandom'. Part
            // of both unique keys because source_key is only meaningful within
            // one source's numbering.
            $table->string('source');

            // This source's identifier for the *record*, not for the page. A
            // casting article yields one casting and many releases, so the
            // casting is 'page:12345' and the seventh Versions row is
            // 'page:12345:row:7'. Keying by page alone would make every row
            // after the first collide on re-import.
            $table->string('source_key');

            // The page the facts were read from. Not nullable: the wiki is
            // CC-BY-SA, attribution is a condition of using it at all, and a
            // row that can't say where it came from is a row the public catalog
            // cannot legally display as imported.
            $table->string('source_url');

            // The source's own revision number, so a re-import can tell what it
            // has already seen. Integer rather than a string because the useful
            // query is a comparison — everything below the dump's revision is
            // stale — and MediaWiki revision ids are monotonic integers.
            // Nullable for a source that doesn't version its content.
            $table->unsignedBigInteger('source_revision_id')->nullable();

            $table->timestamp('imported_at');
            $table->timestamps();

            // The idempotency constraint. A second run over the same dump
            // updates these rows rather than duplicating the catalog, and it is
            // enforced here rather than left to the importer remembering to
            // check first.
            $table->unique(['source', 'source_key']);

            // One provenance row per record. A catalog row has exactly one
            // origin story; a record that later gains facts from a second
            // source is a merge, which is a decision for a human, not a second
            // row appearing quietly.
            $table->unique(['sourceable_type', 'sourceable_id']);

            // "Everything from revision N" and "everything stale". Both lead
            // with source because the leading column of the unique above can't
            // serve either — with a single source in the table, a prefix scan
            // is a full scan.
            $table->index(['source', 'source_revision_id']);
            $table->index(['source', 'imported_at']);
        });

        // A blank URL satisfies NOT NULL while attributing nothing, and it is
        // exactly what a parser that failed to build one would write.
        DB::statement("ALTER TABLE record_sources ADD CONSTRAINT record_sources_url_not_blank CHECK (btrim(source_url) <> '')");
        DB::statement("ALTER TABLE record_sources ADD CONSTRAINT record_sources_key_not_blank CHECK (btrim(source_key) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('record_sources');
    }
};
