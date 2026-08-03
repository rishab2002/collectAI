<?php

namespace Database\Factories;

use App\Models\Casting;
use App\Models\Submission;
use App\Models\SubmissionProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<SubmissionProposal>
 */
class SubmissionProposalFactory extends Factory
{
    /**
     * Defaults to proposing a pending Casting — the middle of the hierarchy, so
     * it has both ancestors to promote and the approval walk gets exercised
     * rather than trivially short-circuited.
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'proposable_type' => Casting::class,
            'proposable_id' => Casting::factory()->pending(),
            'confidence' => fake()->randomFloat(3, 0, 1),
            'decision' => SubmissionProposal::DECISION_PENDING,
            'decided_by' => null,
            'decided_at' => null,
        ];
    }

    /**
     * Propose a specific record instead of the default casting.
     */
    public function forProposable(Model $proposable): static
    {
        return $this->state([
            'proposable_type' => $proposable->getMorphClass(),
            'proposable_id' => $proposable->getKey(),
        ]);
    }

    public function confidence(float $confidence): static
    {
        return $this->state(['confidence' => $confidence]);
    }

    /**
     * Already ruled on. decided_at is set alongside the decision because the
     * table's CHECK constraint rejects a decided row without a timestamp.
     */
    public function approved(?User $admin = null): static
    {
        return $this->state([
            'decision' => SubmissionProposal::DECISION_APPROVED,
            'decided_by' => $admin?->getKey(),
            'decided_at' => now(),
        ]);
    }

    public function rejected(?User $admin = null): static
    {
        return $this->state([
            'decision' => SubmissionProposal::DECISION_REJECTED,
            'decided_by' => $admin?->getKey(),
            'decided_at' => now(),
        ]);
    }
}
