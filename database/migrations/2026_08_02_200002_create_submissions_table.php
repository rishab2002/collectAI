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
        // A submission is one capture session from the phone: the photos taken of a
        // single package, plus whatever the pipeline has made of them so far.
        //
        // It needs its own table because `photos.variation_id` is a non-nullable FK,
        // and the entire premise of the capture app is photographing something
        // *before* it exists in the catalog — at capture time there is no variation
        // to attach to, and inventing a placeholder one would put unreviewed rows in
        // the catalog, which is exactly what the status column exists to prevent.
        // Photos graduate into `photos` once a variation is approved.
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Named `state`, not `status`, specifically to avoid collapsing two
            // unrelated ideas into one word. Catalog `status` is about visibility
            // (should the public see this record); submission `state` is about
            // pipeline lifecycle (how far through the agents has this photo got).
            // A submission can be 'completed' while every record it proposed is
            // still 'pending'.
            $table->string('state')->default('draft');

            // Optional context the admin types in at capture time. Per CLAUDE.md's
            // example flow, knowing Brand + Year + Series up front lets the pipeline
            // narrow to "all known castings in this series for this year" and diff
            // against that, instead of identifying the release from pixels alone.
            // All nullable: a photo with no hints is still a valid submission, just
            // a harder one.
            $table->string('hint_brand')->nullable();
            $table->unsignedSmallInteger('hint_year')->nullable();
            $table->string('hint_series')->nullable();

            // Raw OCR Agent output, stored verbatim against the versioned schema at
            // /app/AI/schemas/ocr_output.json. jsonb rather than text so a later
            // prompt regression can be investigated by querying into past extractions
            // ("every submission where the year came back null") without a reprocess.
            $table->jsonb('ocr_output')->nullable();

            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // The capture app's home screen is "my submissions, newest first".
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE submissions ADD CONSTRAINT submissions_state_check CHECK (state IN ('draft', 'queued', 'processing', 'completed', 'failed'))");

        // Matches the range check already on releases.year — the hint is a claim about
        // the same real-world quantity, so it should not be able to hold a year the
        // release it produces couldn't.
        DB::statement('ALTER TABLE submissions ADD CONSTRAINT submissions_hint_year_range CHECK (hint_year IS NULL OR hint_year BETWEEN 1900 AND 2100)');

        // The queue worker polls for work by state, and finished submissions
        // accumulate forever while in-flight ones are a handful at a time.
        DB::statement("CREATE INDEX submissions_state_active_idx ON submissions (state) WHERE state IN ('queued', 'processing')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
