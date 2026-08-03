<?php

namespace App\Models\Concerns;

use App\Models\SubmissionProposal;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Shared behaviour for the catalog tables the AI pipeline is allowed to create
 * rows in — the ones carrying the pending/live/rejected gate.
 *
 * Note what is deliberately absent: `status` is not in any of these models'
 * fillable lists. Records on these tables are built from LLM-derived JSON, and
 * mass-assigning a payload that happened to contain a `status` key is exactly
 * how unreviewed output would publish itself. Promotion is an explicit,
 * auditable act (see markLive()), never a side effect of filling attributes.
 */
trait HasApprovalStatus
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_LIVE = 'live';

    public const STATUS_REJECTED = 'rejected';

    /**
     * The default scope for anything public-facing. Every catalog read that an
     * ordinary collector can reach should go through this.
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('status', self::STATUS_LIVE);
    }

    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Everything not yet published, which is what the review queue asks for.
     *
     * Written as "not live" rather than "pending or rejected" so it matches the
     * partial index's predicate exactly — Postgres will only use
     * <table>_status_unreviewed_idx for a query whose WHERE clause it can prove
     * implies `status <> 'live'`.
     */
    #[Scope]
    protected function awaitingReview(Builder $query): void
    {
        $query->where('status', '!=', self::STATUS_LIVE);
    }

    /**
     * The pipeline run that proposed this record, if it came from one. Null for
     * records an admin created by hand.
     */
    public function proposal(): MorphOne
    {
        return $this->morphOne(SubmissionProposal::class, 'proposable');
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    /**
     * Records that must already be live for this one to be coherent when live —
     * its parents up the catalog hierarchy. Overridden by each model; the root
     * vocabularies (Make, Brand) depend on nothing.
     *
     * @return list<Model|null>
     */
    public function approvalDependencies(): array
    {
        return [];
    }

    /**
     * Publishes this record, promoting any not-yet-live ancestors first so the
     * catalog never holds a live record hanging off an invisible parent.
     *
     * Nulls are skipped rather than treated as an error: a fantasy casting
     * genuinely has no model, and an optional parent being absent is not the same
     * as it being unapproved.
     */
    public function promoteWithDependencies(): void
    {
        foreach ($this->approvalDependencies() as $dependency) {
            if ($dependency !== null && ! $dependency->isLive()) {
                $dependency->promoteWithDependencies();
            }
        }

        if (! $this->isLive()) {
            $this->markLive();
        }
    }

    public function markLive(): bool
    {
        return $this->forceFill(['status' => self::STATUS_LIVE])->save();
    }

    public function markRejected(): bool
    {
        return $this->forceFill(['status' => self::STATUS_REJECTED])->save();
    }
}
