<?php

namespace Database\Factories;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->admin(),
            'state' => Submission::STATE_DRAFT,
            'hint_brand' => null,
            'hint_year' => null,
            'hint_series' => null,
            'ocr_output' => null,
            'error' => null,
            'processed_at' => null,
        ];
    }

    /**
     * The context an admin types in at capture time, matching CLAUDE.md's worked
     * example (Hot Wheels / 2011 / Night Burnerz).
     */
    public function withHints(
        string $brand = 'Hot Wheels',
        int $year = 2011,
        string $series = 'Night Burnerz',
    ): static {
        return $this->state([
            'hint_brand' => $brand,
            'hint_year' => $year,
            'hint_series' => $series,
        ]);
    }

    public function queued(): static
    {
        return $this->state(['state' => Submission::STATE_QUEUED]);
    }

    /**
     * A finished pipeline run, with OCR output shaped the way the real agent's
     * JSON schema will produce it.
     */
    public function completed(): static
    {
        return $this->state([
            'state' => Submission::STATE_COMPLETED,
            'ocr_output' => [
                'brand' => 'Hot Wheels',
                'year' => 2011,
                'series' => 'Night Burnerz',
                'casting_name' => "'11 Honda Civic",
                'collector_number' => '112/244',
            ],
            'processed_at' => now(),
        ]);
    }

    public function failed(string $error = 'OCR agent returned no readable text.'): static
    {
        return $this->state([
            'state' => Submission::STATE_FAILED,
            'error' => $error,
            'processed_at' => now(),
        ]);
    }
}
