<?php

namespace App\Models\Concerns;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applied to every record the AI pipeline can propose (Casting, Release,
 * Variation). Gives them a status + confidence score without repeating the
 * casts and scopes on each model.
 *
 * Deliberately no global scope: pending records must stay visible to the admin
 * review queue, so callers opt in with ->approved() rather than opting out.
 */
trait HasApprovalStatus
{
    public function initializeHasApprovalStatus(): void
    {
        $this->mergeCasts([
            'status' => ApprovalStatus::class,
            'confidence_score' => 'float',
            'reviewed_at' => 'datetime',
        ]);
    }

    /** Records safe to show in the public catalog. */
    #[Scope]
    protected function approved(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Approved);
    }

    /** The admin review queue. */
    #[Scope]
    protected function pending(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Pending);
    }

    #[Scope]
    protected function rejected(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Rejected);
    }

    /** Proposed records the Review Agent scored at or above the threshold. */
    #[Scope]
    protected function confidentAbove(Builder $query, float $threshold): Builder
    {
        return $query->where('confidence_score', '>=', $threshold);
    }

    public function isApproved(): bool
    {
        return $this->status === ApprovalStatus::Approved;
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    public function approve(): bool
    {
        return $this->forceFill([
            'status' => ApprovalStatus::Approved,
            'reviewed_at' => now(),
        ])->save();
    }

    public function reject(): bool
    {
        return $this->forceFill([
            'status' => ApprovalStatus::Rejected,
            'reviewed_at' => now(),
        ])->save();
    }
}
