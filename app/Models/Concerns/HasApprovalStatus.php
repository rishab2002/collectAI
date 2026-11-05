<?php

namespace App\Models\Concerns;

use App\Models\RecordSource;
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
    /**
     * A record that goes takes its proposal with it.
     *
     * `submission_proposals` is polymorphic, so there is no foreign key to
     * cascade and nothing in the database keeps the two in step — that is the
     * price of the polymorphic table, and it has to be paid in the
     * application. Left unpaid it is paid by the review screen: a proposal
     * pointing at a deleted record renders as null and takes the whole queue
     * down with a 500, which is how this was found (one proposal for a
     * VehicleModel that no longer existed).
     */
    protected static function bootHasApprovalStatus(): void
    {
        static::deleted(function (Model $record): void {
            $record->proposal()->delete();
        });
    }

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

    /**
     * Where this record was imported from, if it was imported rather than
     * photographed. Null for anything the capture pipeline or an admin created.
     *
     * Lives on this trait because the tables carrying the approval gate are
     * exactly the tables a bulk import is allowed to write to — and because the
     * public catalog has to be able to reach the attribution from any of them.
     */
    public function source(): MorphOne
    {
        return $this->morphOne(RecordSource::class, 'sourceable');
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
     * The not-yet-live ancestors that publishing this record would publish with
     * it, nearest parent first.
     *
     * The same walk promoteWithDependencies() performs, collected instead of
     * carried out, so the review screen can tell an admin what one click
     * actually makes public. Keyed while collecting because a submission's
     * proposals can share ancestors, and naming one twice would overstate what
     * is about to happen.
     *
     * @return list<Model>
     */
    public function pendingDependencies(): array
    {
        $pending = [];

        foreach ($this->approvalDependencies() as $dependency) {
            if ($dependency === null || $dependency->isLive()) {
                continue;
            }

            foreach ([$dependency, ...$dependency->pendingDependencies()] as $record) {
                $pending[$record->getMorphClass().':'.$record->getKey()] = $record;
            }
        }

        return array_values($pending);
    }

    /**
     * Why this record must not go live yet, or null if nothing stands in its way.
     *
     * Overridden by the models that carry a "the pipeline produced something
     * incoherent" state. Distinct from an unapproved parent, which the walk
     * fixes by promoting it — a blocker is something no amount of approving can
     * resolve, and needs a human to correct the record first.
     */
    public function approvalBlocker(): ?string
    {
        return null;
    }

    /**
     * Blockers on this record and on every ancestor promoting it would publish.
     *
     * @return list<string>
     */
    public function approvalBlockers(): array
    {
        $blockers = [];

        foreach ([$this, ...$this->pendingDependencies()] as $record) {
            if (($blocker = $record->approvalBlocker()) !== null) {
                $blockers[] = $blocker;
            }
        }

        return $blockers;
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

    /**
     * What turning down a proposal for this record should do to the record.
     *
     * The default is to reject the record itself, because in the capture
     * pipeline the proposal and the record are the same claim: the pipeline
     * invented the row, so "no" means the row shouldn't exist.
     *
     * That stops being true once a proposal can be about a record that arrived
     * from somewhere else. A model overrides this when a proposal may be
     * annotating it rather than proposing it — see Casting.
     */
    public function rejectProposed(): void
    {
        $this->markRejected();
    }

    public function markRejected(): bool
    {
        return $this->forceFill(['status' => self::STATUS_REJECTED])->save();
    }
}
