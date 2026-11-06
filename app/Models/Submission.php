<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One capture session from the phone: the photos of a single package, plus the
 * pipeline's progress through them.
 *
 * `state` here is pipeline lifecycle and is a different question from the
 * catalog `status` on the records this submission proposes — a submission can be
 * COMPLETED while every record it produced is still awaiting review.
 */
#[Fillable([
    'user_id',
    'state',
    'hint_brand',
    'hint_year',
    'hint_series',
    'ocr_output',
    'error',
    'processed_at',
])]
class Submission extends Model
{
    use HasFactory;

    /** Captured on the phone, not yet handed to the pipeline. */
    public const STATE_DRAFT = 'draft';

    public const STATE_QUEUED = 'queued';

    public const STATE_PROCESSING = 'processing';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(SubmissionPhoto::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(SubmissionProposal::class);
    }

    /**
     * Submissions with at least one proposal the admin hasn't ruled on — the
     * review screen's work queue.
     */
    #[Scope]
    protected function awaitingReview(Builder $query): void
    {
        // undecided(), not a bare pending check: a proposal whose record was
        // deleted can never be ruled on, and counting it kept a finished
        // submission in the queue for ever.
        $query->whereHas('proposals', fn (Builder $proposals) => $proposals->undecided());
    }

    /**
     * Matches the partial index on (state) WHERE state IN ('queued','processing').
     */
    #[Scope]
    protected function inFlight(Builder $query): void
    {
        $query->whereIn('state', [self::STATE_QUEUED, self::STATE_PROCESSING]);
    }

    protected function casts(): array
    {
        return [
            'hint_year' => 'integer',
            'ocr_output' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
