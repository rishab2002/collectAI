<?php

namespace Database\Factories;

use App\Models\Submission;
use App\Models\SubmissionPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionPhoto>
 */
class SubmissionPhotoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            // See PhotoFactory: 'local', not the column's 'r2' default.
            'disk' => 'local',
            'path' => 'submissions/'.fake()->uuid().'.jpg',
            'type' => SubmissionPhoto::TYPE_CARD_FRONT,
        ];
    }

    public function cardBack(): static
    {
        return $this->state(['type' => SubmissionPhoto::TYPE_CARD_BACK]);
    }

    public function loose(): static
    {
        return $this->state(['type' => SubmissionPhoto::TYPE_LOOSE]);
    }
}
