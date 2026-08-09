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
     * A finished pipeline run, shaped by app/AI/schemas/ocr_output.json — the
     * schema the OcrAgent holds the model to, so this fixture and real output
     * cannot drift.
     */
    public function completed(): static
    {
        return $this->state([
            'state' => Submission::STATE_COMPLETED,
            'ocr_output' => [
                'schema_version' => 1,
                'brand' => 'Hot Wheels',
                'year' => 2011,
                'series' => 'Night Burnerz',
                'casting_name' => "'11 Honda Civic",
                'collector_number' => '112/244',
                'toy_number' => 'T9765',
                'confidence' => [
                    'brand' => 0.99,
                    'year' => 0.96,
                    'series' => 0.94,
                    'casting_name' => 0.92,
                    'collector_number' => 0.71,
                    'toy_number' => 0.58,
                ],
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
