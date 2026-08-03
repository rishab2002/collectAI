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
        // Links a submission to each catalog row its pipeline run proposed, and holds
        // the Review Agent's verdict on that row.
        //
        // Polymorphic rather than a nullable submission_id on all eight catalog
        // tables: a proposal carries pipeline metadata (which run produced it, how
        // confident the model was, who approved it and when) that has no meaning
        // once a record is live. Spreading those four columns across eight catalog
        // tables would put audit data in the middle of the domain model and make
        // "show me everything awaiting review" an eight-way UNION instead of one
        // indexed read. It also means the review screen can present a submission's
        // proposals as the single unit the admin actually decides on.
        Schema::create('submission_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('submissions')->cascadeOnDelete();

            // Written out rather than using $table->morphs(), which would add its own
            // compound index on the same two columns — the unique constraint below
            // already indexes them, and the pair is the natural key here.
            $table->string('proposable_type');
            $table->unsignedBigInteger('proposable_id');

            // The Review Agent's score, against which the manual-approval threshold is
            // applied. Lives here rather than on the catalog row because it describes
            // the pipeline's belief about a record, not the record itself. 3 decimal
            // places is well past the precision any scoring scheme meaningfully has;
            // nullable because a record an admin created by hand has no score.
            $table->decimal('confidence', 4, 3)->nullable();

            $table->string('decision')->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            // A given catalog row is proposed exactly once. If a later submission
            // photographs the same car, the Comparison Agent's job is to match it to
            // the existing row rather than propose it again — this constraint makes
            // that a hard guarantee instead of a convention the agent has to honour.
            $table->unique(['proposable_type', 'proposable_id']);

            // The review screen's query: this submission's undecided proposals.
            $table->index(['submission_id', 'decision']);
        });

        DB::statement("ALTER TABLE submission_proposals ADD CONSTRAINT submission_proposals_decision_check CHECK (decision IN ('pending', 'approved', 'rejected'))");
        DB::statement('ALTER TABLE submission_proposals ADD CONSTRAINT submission_proposals_confidence_range CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1)');

        // A decision and its metadata travel together: a row is either undecided with
        // no decider and no timestamp, or decided with both. Enforced here because the
        // approval path will eventually be reachable from more than one place (the
        // review UI, a bulk auto-approve above the confidence threshold), and each
        // would otherwise have to remember to set all three.
        DB::statement(<<<'SQL'
            ALTER TABLE submission_proposals ADD CONSTRAINT submission_proposals_decision_metadata CHECK (
                (decision = 'pending' AND decided_by IS NULL AND decided_at IS NULL)
                OR (decision <> 'pending' AND decided_at IS NOT NULL)
            )
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submission_proposals');
    }
};
