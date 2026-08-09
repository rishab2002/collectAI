<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a proposal exist without a capture behind it.
 *
 * `submission_proposals` was built when the only thing that could produce a
 * catalog record was someone photographing a car, so a proposal belonged to a
 * submission by definition. The catalog importer breaks that: it reads facts
 * from a wiki, and the Make/Model split it infers from a casting name is an LLM
 * judgement that needs review exactly like a photographed one — but there is no
 * capture session, no photos, and no user who took them.
 *
 * The alternative was a synthetic photo-less Submission per import run, which
 * would have kept the FK at the cost of making `submissions` mean two different
 * things and putting rows in the capture app's "my submissions" list that were
 * never captured. Nullable says the true thing instead: a proposal is a record
 * the pipeline produced, and where it came from is optional.
 *
 * Nothing else changes. The unique key, the decision CHECK and the approval walk
 * are all indifferent to how the record was proposed, and the review screen
 * still reaches its proposals through a submission — an import's proposals
 * simply aren't in that queue, which is a screen to build, not a constraint to
 * relax.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submission_proposals', function (Blueprint $table) {
            $table->foreignId('submission_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('submission_proposals', function (Blueprint $table) {
            $table->foreignId('submission_id')->nullable(false)->change();
        });
    }
};
