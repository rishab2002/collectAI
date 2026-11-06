<?php

namespace App\Models;

use App\Exceptions\ApprovalBlockedException;
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

    /**
     * Proposals still waiting on a decision — and that one can still be made
     * about.
     *
     * A proposal whose record has been deleted is pending for ever: the
     * review screen cannot render it, so nobody can approve or reject it. If
     * "undecided" counted those, a submission would sit in the queue after
     * every proposal a person can see has been ruled on, reading "0 of 4
     * left" and never leaving. That is what happened. Folding the test in
     * here rather than at each call site is the point — there were three,
     * and the queue was the one that got missed.
     */
    #[Scope]
    protected function undecided(Builder $query): void
    {
        $query->where('decision', self::DECISION_PENDING)->recordStillExists();
    }

    /**
     * Every catalog table a proposal can point at.
     *
     * @return array<class-string<Model>>
     */
    public static function proposableTypes(): array
    {
        return [Make::class, Brand::class, VehicleModel::class, Casting::class,
            CastingVersion::class, Series::class, Release::class, Variation::class];
    }

    /**
     * Only proposals whose record is still there.
     *
     * Nothing in the database enforces this — a polymorphic reference has no
     * foreign key — so a record deleted before `HasApprovalStatus` started
     * tidying up after itself leaves a proposal pointing at nothing, and
     * every screen that renders one dies on a null. Cheap to exclude and
     * cheaper than a 500 in a queue the admin cannot then get past.
     */
    #[Scope]
    protected function recordStillExists(Builder $query): void
    {
        $query->where(function (Builder $any): void {
            foreach (self::proposableTypes() as $class) {
                $any->orWhere(function (Builder $ofType) use ($class): void {
                    $ofType->where('proposable_type', (new $class)->getMorphClass())
                        ->whereIn('proposable_id', $class::query()->select('id'));
                });
            }
        });
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
     *
     * @throws ApprovalBlockedException if this record or one of the ancestors
     *                                  it would promote isn't publishable.
     */
    public function approve(?User $admin = null): void
    {
        $blockers = $this->proposable->approvalBlockers();

        if ($blockers !== []) {
            throw new ApprovalBlockedException($blockers);
        }

        DB::transaction(function () use ($admin): void {
            // Read before the walk, which is the only moment these are still
            // identifiable — afterwards they're indistinguishable from records
            // that were already live.
            $promoted = $this->proposable->pendingDependencies();

            $this->proposable->promoteWithDependencies();

            $decision = [
                'decision' => self::DECISION_APPROVED,
                'decided_by' => $admin?->getKey(),
                'decided_at' => now(),
            ];

            // An ancestor the walk published has been approved in every sense
            // that matters: it is live, by this admin, at this moment. Leaving
            // its own proposal pending would keep the submission sitting in the
            // review queue asking for a ruling that has already been made, and
            // offering an admin buttons for a record that is already public.
            foreach ($promoted as $record) {
                $record->proposal()->undecided()->update($decision);
            }

            $this->forceFill($decision)->save();
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
            // Not markRejected() directly: what "no" costs depends on whether
            // this proposal created the record or only made a claim about one
            // that already existed. See HasApprovalStatus::rejectProposed().
            $this->proposable->rejectProposed();

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
    public function isDecided(): bool
    {
        return $this->decision !== self::DECISION_PENDING;
    }

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
