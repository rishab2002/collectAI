<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

/**
 * One catalog record a pipeline run proposed, and the admin's ruling on it.
 */
#[Fillable([
    'submission_id',
    'proposable_type',
    'proposable_id',
    'confidence',
    'decision',
    'decided_by',
    'decided_at',
])]
class SubmissionProposal extends Model
{
    use HasFactory;

    public const DECISION_PENDING = 'pending';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * The proposed catalog record itself — a Make, Casting, Release, and so on.
     */
    public function proposable(): MorphTo
    {
        return $this->morphTo();
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    #[Scope]
    protected function undecided(Builder $query): void
    {
        $query->where('decision', self::DECISION_PENDING);
    }

    /**
     * Publishes the proposed record along with anything it hangs off.
     *
     * The dependency walk is the important part: a photo of an unknown car can
     * propose a make, model, casting, version, series, release and variation in
     * one run, and approving only the variation would leave it live underneath a
     * chain of records the public still can't see. Promoting the ancestors first
     * means the catalog never contains a live record with an invisible parent.
     *
     * Wrapped in a transaction so a failure part-way up the chain doesn't leave
     * exactly that half-published state behind.
     */
    public function approve(?User $admin = null): void
    {
        DB::transaction(function () use ($admin): void {
            $this->proposable->promoteWithDependencies();

            $this->forceFill([
                'decision' => self::DECISION_APPROVED,
                'decided_by' => $admin?->getKey(),
                'decided_at' => now(),
            ])->save();
        });
    }

    /**
     * Marks the record rejected rather than deleting it, so a later submission
     * photographing the same car can be recognised as something already turned
     * down instead of being proposed from scratch again.
     *
     * Dependencies are deliberately left alone: rejecting a variation says
     * nothing about whether its casting was also wrong, and cascading would
     * throw away correct records alongside the incorrect one.
     */
    public function reject(?User $admin = null): void
    {
        DB::transaction(function () use ($admin): void {
            $this->proposable->markRejected();

            $this->forceFill([
                'decision' => self::DECISION_REJECTED,
                'decided_by' => $admin?->getKey(),
                'decided_at' => now(),
            ])->save();
        });
    }

    /**
     * Whether this needs a human. Below the threshold — or with no score at all —
     * an admin rules on it by hand.
     *
     * A null score is treated as "needs review" rather than as a zero, so a
     * pipeline bug that drops the score can never widen what auto-approves.
     */
    public function requiresManualReview(?float $threshold = null): bool
    {
        $threshold ??= config('catalog.review.auto_approve_threshold');

        return $this->confidence === null || (float) $this->confidence < $threshold;
    }

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:3',
            'decided_at' => 'datetime',
        ];
    }
}
