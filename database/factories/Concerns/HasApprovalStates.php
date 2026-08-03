<?php

namespace Database\Factories\Concerns;

/**
 * States for the catalog factories whose models carry the approval gate.
 *
 * The factories themselves default to 'live', which is the opposite of the
 * column default. That's deliberate: the column defaults to 'pending' so
 * unreviewed pipeline output can't publish itself by accident, but a test or
 * seeder building catalog fixtures almost always wants records that behave like
 * an established catalog. Tests exercising the review workflow ask for
 * ->pending() explicitly, which reads better than the reverse.
 *
 * Constants are read off the model class rather than referenced through the
 * HasApprovalStatus trait, since PHP does not allow constant access via a trait
 * name.
 */
trait HasApprovalStates
{
    public function pending(): static
    {
        $model = $this->modelName();

        return $this->state(['status' => $model::STATUS_PENDING]);
    }

    public function rejected(): static
    {
        $model = $this->modelName();

        return $this->state(['status' => $model::STATUS_REJECTED]);
    }
}
